<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Monitoring\Domain;

use Kadupul\Telemetry\Contract\MetricId;
use Kadupul\Telemetry\Contract\MetricType;

/**
 * Checks a decoded pack against docs/schemas/monitoring-pack.schema.json and
 * the semantic rules listed in docs/model-driven-monitoring.md, and builds a
 * MonitoringPack only when nothing is wrong.
 *
 * The input is JSON decoded with objects as \stdClass, so an empty object and
 * an empty array stay distinct the way the schema distinguishes them. No JSON
 * Schema library is available to the Domain, so the schema's rules are written
 * out here; PackSchemaParityTest compares the verdicts with a real validator.
 */
final class PackValidator
{
    private const PROTOCOLS = ['snmp', 'gnmi', 'redfish', 'netconf', 'restconf'];
    // /D: without it PCRE lets $ match before a trailing newline, which JSON Schema does not.
    private const PACK_NAME = '/^[a-z][a-z0-9]*(-[a-z0-9]+)*$/D';
    private const METRIC_ID = '/^[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*)+$/D';
    private const LABEL = '/^[a-z][a-z0-9_]*$/D';
    private const CAPABILITY = '/^[a-z0-9][a-z0-9._:\/-]*$/D';
    private const OID = '/^[0-9]+(\.[0-9]+)+$/D';
    private const RANGE = '/^[0-9A-Za-z.^~<>=!*| ,-]+$/D';
    private const REDFISH_PATH = '/^\/redfish\/v1\//';
    private const ABSOLUTE_PATH = '/^\//';

    /** @var list<PackViolation> */
    private array $violations = [];

    public function __construct(private readonly string $runningVersion)
    {
        if (!VersionConstraint::isVersion($runningVersion)) {
            throw new \InvalidArgumentException(sprintf('The running Kadupul version "%s" is not a version number.', $runningVersion));
        }
    }

    /** @throws InvalidPack with every violation when the document is not a valid pack */
    public function parse(mixed $document, string $source): MonitoringPack
    {
        $violations = $this->validate($document);
        if ($violations !== []) {
            throw new InvalidPack($source, $violations);
        }
        assert($document instanceof \stdClass);

        return $this->build($document);
    }

    /** @return list<PackViolation> empty when the document is a valid pack */
    public function validate(mixed $document): array
    {
        $this->violations = [];
        $root = $this->object($document, '', ['schema_version', 'name', 'version', 'capabilities', 'model_families', 'discovery', 'metrics', 'collection', 'compatibility'], ['description', 'graphs', 'alerts']);
        if ($root !== null) {
            $this->structure($root);
            $this->semantics($root);
        }
        $found = $this->violations;
        $this->violations = [];

        return $found;
    }

    private function structure(\stdClass $pack): void
    {
        if ($this->has($pack, 'schema_version') && !$this->equalsNumber($pack->schema_version, 1)) {
            $this->violation('/schema_version', 'must be 1; this validator reads schema version 1 only');
        }
        $this->string($pack, 'name', '', 1, 64, self::PACK_NAME, 'lowercase words joined by hyphens, such as openconfig-interfaces');
        $this->string($pack, 'version', '', 1, null, SemanticVersion::PATTERN, 'a semantic version such as 1.0.0');
        $this->string($pack, 'description', '', 1, 500);

        if ($this->has($pack, 'capabilities')) {
            $capabilities = $this->object($pack->capabilities, '/capabilities', [], ['all', 'any'], minProperties: 1);
            foreach (['all', 'any'] as $list) {
                if ($capabilities !== null && $this->has($capabilities, $list)) {
                    $this->stringList($capabilities->{$list}, '/capabilities/' . $list, 1, 128, self::CAPABILITY, 'a capability name such as openconfig-interfaces or snmp:if-mib', 1);
                }
            }
        }
        if ($this->has($pack, 'model_families')) {
            $families = $this->array($pack->model_families, '/model_families', 1, true);
            foreach ($families ?? [] as $i => $family) {
                $this->enum($family, '/model_families/' . $i, ModelReference::FAMILIES);
            }
        }
        if ($this->has($pack, 'discovery')) {
            $this->discovery($pack->discovery);
        }
        if ($this->has($pack, 'metrics')) {
            foreach ($this->array($pack->metrics, '/metrics', 1, false) ?? [] as $i => $metric) {
                $this->metric($metric, '/metrics/' . $i);
            }
        }
        if ($this->has($pack, 'collection')) {
            $collection = $this->object($pack->collection, '/collection', ['protocol_preference', 'interval_seconds'], []);
            if ($collection !== null) {
                if ($this->has($collection, 'protocol_preference')) {
                    foreach ($this->array($collection->protocol_preference, '/collection/protocol_preference', 1, true) ?? [] as $i => $protocol) {
                        $this->enum($protocol, '/collection/protocol_preference/' . $i, self::PROTOCOLS);
                    }
                }
                if ($this->has($collection, 'interval_seconds')) {
                    $interval = $this->object($collection->interval_seconds, '/collection/interval_seconds', ['min', 'default'], [], extensions: false);
                    foreach (['min', 'default'] as $bound) {
                        if ($interval !== null && $this->has($interval, $bound)) {
                            $this->integer($interval->{$bound}, '/collection/interval_seconds/' . $bound, 1);
                        }
                    }
                }
            }
        }
        if ($this->has($pack, 'graphs')) {
            foreach ($this->array($pack->graphs, '/graphs', 0, false) ?? [] as $i => $graph) {
                $p = '/graphs/' . $i;
                $graph = $this->object($graph, $p, ['name', 'metrics', 'unit'], []);
                if ($graph !== null) {
                    $this->string($graph, 'name', $p, 1, 128);
                    $this->string($graph, 'unit', $p, 1, 32);
                    if ($this->has($graph, 'metrics')) {
                        $this->stringList($graph->metrics, $p . '/metrics', 1, 128, self::METRIC_ID, 'a dotted lowercase metric id such as interface.in_octets', 1);
                    }
                }
            }
        }
        if ($this->has($pack, 'alerts')) {
            foreach ($this->array($pack->alerts, '/alerts', 0, false) ?? [] as $i => $alert) {
                $this->alert($alert, '/alerts/' . $i);
            }
        }
        if ($this->has($pack, 'compatibility')) {
            $compatibility = $this->object($pack->compatibility, '/compatibility', ['kadupul'], ['legacy_templates']);
            if ($compatibility !== null) {
                $this->string($compatibility, 'kadupul', '/compatibility', 1, 100, self::RANGE, 'a version constraint such as ^1.0 or >=1.2 <2.0');
                if ($this->has($compatibility, 'legacy_templates')) {
                    $legacy = $this->object($compatibility->legacy_templates, '/compatibility/legacy_templates', [], ['graph_templates', 'data_queries'], extensions: false);
                    foreach (['graph_templates', 'data_queries'] as $list) {
                        if ($legacy !== null && $this->has($legacy, $list)) {
                            $this->stringList($legacy->{$list}, '/compatibility/legacy_templates/' . $list, 1, null, null, null, 0);
                        }
                    }
                }
            }
        }
    }

    private function discovery(mixed $value): void
    {
        $discovery = $this->object($value, '/discovery', ['resource', 'identity'], ['mappings']);
        if ($discovery === null) {
            return;
        }
        $this->string($discovery, 'resource', '/discovery', 1, null, self::LABEL, 'a lowercase resource kind such as interface');
        if ($this->has($discovery, 'identity')) {
            $identity = $this->object($discovery->identity, '/discovery/identity', ['key'], ['description']);
            if ($identity !== null) {
                $this->string($identity, 'key', '/discovery/identity', 1, 64, self::LABEL, 'a lowercase label name such as name');
                $this->string($identity, 'description', '/discovery/identity', 0);
            }
        }
        if (!$this->has($discovery, 'mappings')) {
            return;
        }
        $shapes = [
            'snmp' => [['identity_oid'], ['index_is_identity']],
            'gnmi' => [['path'], ['origin']],
            'redfish' => [['collection_path'], []],
            'netconf' => [['xpath'], []],
            'restconf' => [['resource_path'], []],
        ];
        $mappings = $this->object($discovery->mappings, '/discovery/mappings', [], self::PROTOCOLS, extensions: false);
        foreach (self::PROTOCOLS as $protocol) {
            if ($mappings === null || !$this->has($mappings, $protocol)) {
                continue;
            }
            $p = '/discovery/mappings/' . $protocol;
            $mapping = $this->object($mappings->{$protocol}, $p, ...$shapes[$protocol]);
            if ($mapping === null) {
                continue;
            }
            $this->string($mapping, 'identity_oid', $p, 0, null, self::OID, 'a numeric OID such as 1.3.6.1.2.1.31.1.1.1.1');
            $this->string($mapping, 'path', $p, 1);
            $this->string($mapping, 'origin', $p, 0);
            $this->string($mapping, 'collection_path', $p, 0, null, self::REDFISH_PATH, 'a path under /redfish/v1/');
            $this->string($mapping, 'xpath', $p, 1);
            $this->string($mapping, 'resource_path', $p, 0, null, self::ABSOLUTE_PATH, 'an absolute path starting with /');
            if ($this->has($mapping, 'index_is_identity') && !is_bool($mapping->index_is_identity)) {
                $this->violation($p . '/index_is_identity', 'must be a boolean');
            }
        }
    }

    private function metric(mixed $value, string $p): void
    {
        $metric = $this->object($value, $p, ['id', 'type', 'unit', 'mappings'], ['counter_bits', 'labels', 'description']);
        if ($metric === null) {
            return;
        }
        $this->string($metric, 'id', $p, 1, 128, self::METRIC_ID, 'a dotted lowercase metric id such as interface.in_octets');
        if ($this->has($metric, 'type')) {
            $this->enum($metric->type, $p . '/type', ['counter', 'gauge']);
        }
        $this->string($metric, 'unit', $p, 1, 32);
        $this->string($metric, 'description', $p, 0, 500);
        if ($this->has($metric, 'counter_bits')) {
            $this->enum($metric->counter_bits, $p . '/counter_bits', [32, 64]);
            if ($this->has($metric, 'type') && $metric->type === 'gauge') {
                $this->violation($p . '/counter_bits', 'not allowed when type is gauge');
            }
        }
        if ($this->has($metric, 'labels')) {
            $this->stringList($metric->labels, $p . '/labels', 1, 64, self::LABEL, 'a lowercase label name such as name', 0);
        }
        if (!$this->has($metric, 'mappings')) {
            return;
        }
        $shapes = [
            'snmp' => [['oid'], ['mib', 'object', 'scale', 'value_map']],
            'gnmi' => [['path'], ['origin', 'scale', 'value_map']],
            'redfish' => [['resource_path', 'property'], ['scale', 'value_map']],
            'netconf' => [['xpath'], ['scale', 'value_map']],
            'restconf' => [['resource_path'], ['scale', 'value_map']],
        ];
        $mappings = $this->object($metric->mappings, $p . '/mappings', [], self::PROTOCOLS, extensions: false, minProperties: 1);
        foreach (self::PROTOCOLS as $protocol) {
            if ($mappings === null || !$this->has($mappings, $protocol)) {
                continue;
            }
            $mp = $p . '/mappings/' . $protocol;
            $mapping = $this->object($mappings->{$protocol}, $mp, ...$shapes[$protocol]);
            if ($mapping === null) {
                continue;
            }
            $this->string($mapping, 'oid', $mp, 0, null, self::OID, 'a numeric OID such as 1.3.6.1.2.1.31.1.1.1.6');
            foreach (['mib', 'object', 'origin'] as $text) {
                $this->string($mapping, $text, $mp, 0);
            }
            $this->string($mapping, 'path', $mp, 1);
            $this->string($mapping, 'xpath', $mp, 1);
            $this->string($mapping, 'property', $mp, 1);
            $this->string($mapping, 'resource_path', $mp, 0, null, $protocol === 'redfish' ? self::REDFISH_PATH : self::ABSOLUTE_PATH, $protocol === 'redfish' ? 'a path under /redfish/v1/' : 'an absolute path starting with /');
            if ($this->has($mapping, 'scale') && $this->number($mapping->scale, $mp . '/scale') && $mapping->scale <= 0) {
                $this->violation($mp . '/scale', 'must be greater than 0');
            }
            if ($this->has($mapping, 'value_map')) {
                $this->valueMap($mapping->value_map, $mp . '/value_map');
            }
        }
    }

    private function valueMap(mixed $value, string $p): void
    {
        if (!$value instanceof \stdClass) {
            $this->violation($p, 'must be an object');

            return;
        }
        $entries = get_object_vars($value);
        if ($entries === []) {
            $this->violation($p, 'must contain at least 1 field');
        }
        foreach ($entries as $key => $number) {
            $this->integer($number, $this->child($p, (string) $key), null);
        }
    }

    private function alert(mixed $value, string $p): void
    {
        $alert = $this->object($value, $p, ['name', 'metric', 'condition'], ['severity']);
        if ($alert === null) {
            return;
        }
        $this->string($alert, 'name', $p, 1, 128);
        $this->string($alert, 'metric', $p, 1, 128, self::METRIC_ID, 'a dotted lowercase metric id such as interface.oper_status');
        if ($this->has($alert, 'severity')) {
            $this->enum($alert->severity, $p . '/severity', ['info', 'warning', 'critical']);
        }
        if (!$this->has($alert, 'condition')) {
            return;
        }
        $condition = $this->object($alert->condition, $p . '/condition', ['operator', 'threshold'], ['for_seconds'], extensions: false);
        if ($condition === null) {
            return;
        }
        if ($this->has($condition, 'operator')) {
            $this->enum($condition->operator, $p . '/condition/operator', ['gt', 'gte', 'lt', 'lte', 'eq', 'neq']);
        }
        if ($this->has($condition, 'threshold')) {
            $this->number($condition->threshold, $p . '/condition/threshold');
        }
        if ($this->has($condition, 'for_seconds')) {
            $this->integer($condition->for_seconds, $p . '/condition/for_seconds', 0);
        }
    }

    private function semantics(\stdClass $pack): void
    {
        $ids = [];
        $mapped = [];
        foreach ($this->has($pack, 'metrics') && is_array($pack->metrics) ? $pack->metrics : [] as $i => $metric) {
            if (!$metric instanceof \stdClass) {
                continue;
            }
            if ($this->has($metric, 'id') && is_string($metric->id)) {
                if (isset($ids[$metric->id])) {
                    $this->violation('/metrics/' . $i . '/id', sprintf('%s duplicates /metrics/%d/id; metric ids must be unique within a pack', $metric->id, $ids[$metric->id]));
                } else {
                    $ids[$metric->id] = $i;
                }
            }
            if ($this->has($metric, 'mappings') && $metric->mappings instanceof \stdClass) {
                foreach (array_keys(get_object_vars($metric->mappings)) as $protocol) {
                    $mapped[(string) $protocol] = true;
                }
            }
        }
        foreach ($this->has($pack, 'graphs') && is_array($pack->graphs) ? $pack->graphs : [] as $g => $graph) {
            if ($graph instanceof \stdClass && $this->has($graph, 'metrics') && is_array($graph->metrics)) {
                foreach ($graph->metrics as $k => $id) {
                    if (is_string($id) && !isset($ids[$id])) {
                        $this->violation(sprintf('/graphs/%d/metrics/%d', $g, $k), sprintf('names %s, which is not a metric of this pack', $id));
                    }
                }
            }
        }
        foreach ($this->has($pack, 'alerts') && is_array($pack->alerts) ? $pack->alerts : [] as $a => $alert) {
            if ($alert instanceof \stdClass && $this->has($alert, 'metric') && is_string($alert->metric) && !isset($ids[$alert->metric])) {
                $this->violation(sprintf('/alerts/%d/metric', $a), sprintf('names %s, which is not a metric of this pack', $alert->metric));
            }
        }
        $collection = $this->has($pack, 'collection') && $pack->collection instanceof \stdClass ? $pack->collection : new \stdClass();
        $interval = $this->has($collection, 'interval_seconds') && $collection->interval_seconds instanceof \stdClass ? $collection->interval_seconds : new \stdClass();
        if ($this->has($interval, 'min') && $this->has($interval, 'default') && is_int($interval->min) && is_int($interval->default) && $interval->default < $interval->min) {
            $this->violation('/collection/interval_seconds/default', sprintf('must be at least min (%d)', $interval->min));
        }
        foreach ($this->has($collection, 'protocol_preference') && is_array($collection->protocol_preference) ? $collection->protocol_preference : [] as $i => $protocol) {
            if (is_string($protocol) && in_array($protocol, self::PROTOCOLS, true) && !isset($mapped[$protocol])) {
                $this->violation('/collection/protocol_preference/' . $i, sprintf('no metric maps %s, so it can never be chosen', $protocol));
            }
        }
        $compatibility = $this->has($pack, 'compatibility') && $pack->compatibility instanceof \stdClass ? $pack->compatibility : new \stdClass();
        if ($this->has($compatibility, 'kadupul') && is_string($compatibility->kadupul) && preg_match(self::RANGE, $compatibility->kadupul) === 1) {
            try {
                if (!VersionConstraint::parse($compatibility->kadupul)->isSatisfiedBy($this->runningVersion)) {
                    $this->violation('/compatibility/kadupul', sprintf('%s is not satisfied by the running Kadupul version %s', $compatibility->kadupul, $this->runningVersion));
                }
            } catch (InvalidVersionConstraint $e) {
                $this->violation('/compatibility/kadupul', 'cannot be parsed as a version constraint: ' . $e->getMessage());
            }
        }
    }

    private function build(\stdClass $pack): MonitoringPack
    {
        $metrics = [];
        foreach ($pack->metrics as $metric) {
            $mappings = [];
            foreach (get_object_vars($metric->mappings) as $protocol => $mapping) {
                $mappings[(string) $protocol] = new PackMapping(
                    (string) $protocol,
                    $mapping->oid ?? $mapping->path ?? $mapping->resource_path ?? $mapping->xpath,
                    $mapping->property ?? null,
                    $mapping->scale ?? null,
                    isset($mapping->value_map) ? array_map('intval', get_object_vars($mapping->value_map)) : null,
                );
            }
            $metrics[] = new PackMetric(
                new MetricId($metric->id),
                MetricType::from($metric->type),
                $metric->unit,
                isset($metric->counter_bits) ? (int) $metric->counter_bits : null,
                $metric->labels ?? [],
                $mappings,
            );
        }

        return new MonitoringPack(
            $pack->name,
            SemanticVersion::parse($pack->version),
            new CapabilityRequirement($pack->capabilities->all ?? [], $pack->capabilities->any ?? []),
            $pack->model_families,
            new PackDiscovery($pack->discovery->resource, $pack->discovery->identity->key),
            $metrics,
            $pack->collection->protocol_preference,
            (int) $pack->collection->interval_seconds->min,
            (int) $pack->collection->interval_seconds->default,
            array_map(static fn(\stdClass $g): PackGraph => new PackGraph($g->name, $g->metrics, $g->unit), $pack->graphs ?? []),
            $pack->compatibility->kadupul,
        );
    }

    /**
     * @param list<string> $required
     * @param list<string> $optional
     */
    private function object(mixed $value, string $p, array $required, array $optional, bool $extensions = true, int $minProperties = 0): ?\stdClass
    {
        if (!$value instanceof \stdClass) {
            $this->violation($p, 'must be an object');

            return null;
        }
        $fields = array_map('strval', array_keys(get_object_vars($value)));
        foreach ($fields as $field) {
            if (in_array($field, $required, true) || in_array($field, $optional, true)) {
                continue;
            }
            if ($extensions && str_starts_with($field, 'x-')) {
                continue;
            }
            $this->violation($this->child($p, $field), $extensions ? 'unknown field; only fields starting with x- may be added here' : 'unknown field; this object accepts no other fields');
        }
        foreach ($required as $field) {
            if (!in_array($field, $fields, true)) {
                $this->violation($this->child($p, $field), 'is required');
            }
        }
        if (count($fields) < $minProperties) {
            $this->violation($p, sprintf('must contain at least %d field', $minProperties));
        }

        return $value;
    }

    /** @return array<int, mixed>|null */
    private function array(mixed $value, string $p, int $minItems, bool $unique): ?array
    {
        if (!is_array($value)) {
            $this->violation($p, 'must be an array');

            return null;
        }
        if (count($value) < $minItems) {
            $this->violation($p, sprintf('must contain at least %d item', $minItems));
        }
        if ($unique) {
            $seen = [];
            foreach ($value as $item) {
                $key = json_encode($item);
                if (isset($seen[$key])) {
                    $this->violation($p, sprintf('must not repeat %s', $key));
                }
                $seen[$key] = true;
            }
        }

        return $value;
    }

    private function stringList(mixed $value, string $p, int $minLength, ?int $maxLength, ?string $pattern, ?string $expected, int $minItems): void
    {
        foreach ($this->array($value, $p, $minItems, true) ?? [] as $i => $item) {
            $this->checkString($item, $p . '/' . $i, $minLength, $maxLength, $pattern, $expected);
        }
    }

    /** Checks $object->$field when it is present; absence is the object check's concern. */
    private function string(\stdClass $object, string $field, string $p, int $minLength, ?int $maxLength = null, ?string $pattern = null, ?string $expected = null): void
    {
        if ($this->has($object, $field)) {
            $this->checkString($object->{$field}, $this->child($p, $field), $minLength, $maxLength, $pattern, $expected);
        }
    }

    private function checkString(mixed $value, string $p, int $minLength, ?int $maxLength, ?string $pattern, ?string $expected): void
    {
        if (!is_string($value)) {
            $this->violation($p, 'must be a string');

            return;
        }
        $length = mb_strlen($value, 'UTF-8');
        if ($length < $minLength) {
            $this->violation($p, sprintf('must be at least %d character%s long', $minLength, $minLength === 1 ? '' : 's'));
        }
        if ($maxLength !== null && $length > $maxLength) {
            $this->violation($p, sprintf('must be at most %d characters long', $maxLength));
        }
        if ($pattern !== null && preg_match($pattern, $value) !== 1) {
            $this->violation($p, sprintf('must be %s; got %s', $expected, json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)));
        }
    }

    private function integer(mixed $value, string $p, ?int $minimum): void
    {
        // JSON Schema counts 60.0 as an integer. A number too large for PHP
        // arrives as a string (JSON_BIGINT_AS_STRING) and is rejected here.
        $integral = is_int($value) || (is_float($value) && is_finite($value) && floor($value) === $value && abs($value) < 2 ** 53);
        if (!$integral) {
            $this->violation($p, 'must be an integer');

            return;
        }
        if ($minimum !== null && $value < $minimum) {
            $this->violation($p, sprintf('must be at least %d', $minimum));
        }
    }

    private function number(mixed $value, string $p): bool
    {
        if (is_int($value) || (is_float($value) && is_finite($value))) {
            return true;
        }
        $this->violation($p, 'must be a number');

        return false;
    }

    /** @param list<string|int> $allowed */
    private function enum(mixed $value, string $p, array $allowed): void
    {
        foreach ($allowed as $candidate) {
            if (is_int($candidate) ? $this->equalsNumber($value, $candidate) : $value === $candidate) {
                return;
            }
        }
        $this->violation($p, 'must be one of ' . implode(', ', $allowed));
    }

    private function equalsNumber(mixed $value, int $expected): bool
    {
        return (is_int($value) || is_float($value)) && $value == $expected;
    }

    private function has(\stdClass $object, string $field): bool
    {
        return property_exists($object, $field);
    }

    private function child(string $p, string $key): string
    {
        return $p . '/' . str_replace(['~', '/'], ['~0', '~1'], $key);
    }

    private function violation(string $p, string $message): void
    {
        $this->violations[] = new PackViolation($p === '' ? '(document)' : $p, $message);
    }
}
