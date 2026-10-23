<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Monitoring\Infrastructure\Legacy;

use Kadupul\Monitoring\Application\Port\ExistingMonitoring;
use Kadupul\Monitoring\Domain\BindingKey;
use Kadupul\Monitoring\Domain\BindingMatch;
use Kadupul\Monitoring\Domain\InvalidBinding;
use Kadupul\Monitoring\Domain\MatchConfidence;
use Kadupul\Monitoring\Domain\MonitoredSeries;
use Kadupul\Monitoring\Domain\RrdDataSourceName;
use Kadupul\Monitoring\Domain\StorageField;
use Kadupul\Telemetry\Contract\MetricId;
use Kadupul\Telemetry\Contract\ResourceId;

/**
 * What legacy data sources already collect, for planner overlap detection,
 * and how well a legacy field matches a logical series, for binding.
 *
 * Classification of a series (device, metric, resource) against the
 * device's legacy fields. Protocol is not compared: a binding names where a
 * series is stored, whichever protocol later collects it.
 *
 * Resource evidence for a data source, from its data-query index:
 * - strong: a present host_snmp_cache value of an identity field for the
 *   resource kind (ifName, ifDescr or ifIndex for interfaces) equals the
 *   resource identity;
 * - weak: only the stored index equals the identity, or the matching cache
 *   value is no longer present (the device was reindexed).
 * Metric evidence for a field:
 * - strong: FIELD_SEMANTICS maps the data-source name to the metric;
 * - weak: the name, ignoring case and punctuation, equals the metric's last
 *   segment (in_octets for interface.in_octets).
 * A field with evidence on both axes is a candidate. Then:
 * - Confirmed: exactly one candidate, both kinds of evidence strong, the
 *   data-source type is one a binding can expect and the name is valid;
 * - Ambiguous: more than one candidate, for example two indexes reporting
 *   the same interface name;
 * - Heuristic: exactly one candidate with weak evidence on either axis, or
 *   one whose type or name a binding cannot use;
 * - None: no candidate, or no identity fields are known for the kind.
 * Only Confirmed may become a binding; RegisterBinding refuses the rest.
 *
 * monitoredSeries() reports every field with strong metric evidence whose
 * data source has an ifName hint, ambiguous names included: an overlap is
 * advisory, and over-reporting one only stops duplicate collection.
 */
final class LegacyExistingMonitoring implements ExistingMonitoring
{
    /**
     * Reviewed map of stock legacy interface data-source names to metric
     * ids. Add an entry only after checking the template that defines the
     * name collects exactly that metric; an entry here is what lets a
     * legacy field be confirmed.
     */
    public const FIELD_SEMANTICS = [
        'traffic_in' => 'interface.in_octets',
        'traffic_out' => 'interface.out_octets',
        'errors_in' => 'interface.in_errors',
        'errors_out' => 'interface.out_errors',
        'discards_in' => 'interface.in_discards',
        'discards_out' => 'interface.out_discards',
    ];

    public function __construct(private readonly LegacyDataSourceDiscovery $discovery) {}

    public function monitoredSeries(int $deviceId): array
    {
        $series = [];
        foreach ($this->discovery->dataSources($deviceId) as $source) {
            $name = $source->resourceHints['ifName'] ?? '';
            if ($name === '') {
                continue;
            }
            foreach ($source->fields as $field) {
                $metric = self::FIELD_SEMANTICS[$field->name] ?? null;
                if ($metric !== null) {
                    $series[] = new MonitoredSeries(new ResourceId('interface', $name), new MetricId($metric), sprintf('legacy:data-source/%d:%s', $source->localDataId, $field->name), $source->protocol);
                }
            }
        }

        return $series;
    }

    public function match(BindingKey $key): BindingMatch
    {
        $resource = $key->resource;
        $identityFields = LegacyDataSourceDiscovery::IDENTITY_FIELDS[$resource->kind] ?? null;
        if ($identityFields === null) {
            return new BindingMatch(MatchConfidence::None, [sprintf('No legacy identity fields are known for %s resources.', $resource->kind)], null, null);
        }
        $vanished = [];
        foreach ($this->discovery->cache($key->deviceId) as $entry) {
            if (!$entry['present'] && in_array($entry['field'], $identityFields, true) && $entry['value'] === $resource->identity) {
                $vanished[$entry['query'] . '|' . $entry['index']] = $entry['field'];
            }
        }
        $candidates = [];
        foreach ($this->discovery->dataSources($key->deviceId) as $source) {
            $resourceEvidence = $this->resourceEvidence($source, $identityFields, $resource->identity, $vanished);
            if ($resourceEvidence === null) {
                continue;
            }
            foreach ($source->fields as $field) {
                $metricEvidence = $this->metricEvidence($field->name, $key->metric);
                if ($metricEvidence !== null) {
                    $candidates[] = [$source, $field, $resourceEvidence, $metricEvidence];
                }
            }
        }
        $describe = static fn(array $c): string => sprintf('data source %d field %s (%s; %s)', $c[0]->localDataId, $c[1]->name, $c[2][1], $c[3][1]);
        if ($candidates === []) {
            return new BindingMatch(MatchConfidence::None, [sprintf('No legacy field on device %d carries %s for %s.', $key->deviceId, $key->metric->toString(), $resource->toString())], null, null);
        }
        if (count($candidates) > 1) {
            return new BindingMatch(MatchConfidence::Ambiguous, array_map(static fn(array $c): string => 'Candidate ' . $describe($c) . '.', $candidates), null, null);
        }
        [$source, $field, [$resourceStrength], [$metricStrength]] = $candidates[0];
        $storage = null;
        try {
            $storage = new StorageField($source->localDataId, new RrdDataSourceName($field->name));
        } catch (InvalidBinding $e) {
            return new BindingMatch(MatchConfidence::Heuristic, ['Only candidate is ' . $describe($candidates[0]) . ', but ' . $e->getMessage()], null, null);
        }
        if ($resourceStrength !== 'strong' || $metricStrength !== 'strong') {
            return new BindingMatch(MatchConfidence::Heuristic, ['Only candidate is ' . $describe($candidates[0]) . ', on name evidence alone.'], $storage, $field->type);
        }
        if ($field->type === null) {
            return new BindingMatch(MatchConfidence::Heuristic, ['Only candidate is ' . $describe($candidates[0]) . sprintf(', but data-source type %d cannot be bound.', $field->typeId)], $storage, null);
        }

        return new BindingMatch(MatchConfidence::Confirmed, ['Device, resource and metric agree: ' . $describe($candidates[0]) . '.'], $storage, $field->type);
    }

    /**
     * @param list<string> $identityFields
     * @param array<string, string> $vanished
     * @return array{string, string}|null strength and reason
     */
    private function resourceEvidence(LegacyDataSource $source, array $identityFields, string $identity, array $vanished): ?array
    {
        if ($source->dataQueryId < 1) {
            return null;
        }
        foreach ($identityFields as $field) {
            if (($source->resourceHints[$field] ?? null) === $identity) {
                return ['strong', sprintf('%s of index %s is %s', $field, $source->index, $identity)];
            }
        }
        $key = $source->dataQueryId . '|' . $source->index;
        if (isset($vanished[$key])) {
            return ['weak', sprintf('%s of index %s was %s but is no longer present', $vanished[$key], $source->index, $identity)];
        }
        if ($source->resourceHints === [] && $source->index === $identity) {
            return ['weak', sprintf('only the stored index %s equals the identity', $source->index)];
        }

        return null;
    }

    /** @return array{string, string}|null strength and reason */
    private function metricEvidence(string $name, MetricId $metric): ?array
    {
        if ((self::FIELD_SEMANTICS[$name] ?? null) === $metric->toString()) {
            return ['strong', sprintf('%s is a reviewed name for %s', $name, $metric->toString())];
        }
        $segments = explode('.', $metric->toString());
        $normalize = static fn(string $text): string => (string) preg_replace('/[^a-z0-9]/', '', strtolower($text));
        if (!isset(self::FIELD_SEMANTICS[$name]) && $normalize($name) === $normalize(end($segments))) {
            return ['weak', sprintf('%s only resembles %s', $name, $metric->toString())];
        }

        return null;
    }
}
