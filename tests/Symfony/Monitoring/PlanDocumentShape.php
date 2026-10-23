<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Tests\Monitoring;

/**
 * The structural rules of docs/schemas/collection-plan.schema.json and
 * collection-plan.v2.schema.json written out for tests: required keys,
 * closed objects, types, patterns and the per-protocol subscription rules,
 * plus, for version 2, the metric binding and the ban on keys that could
 * carry storage detail. It always runs, so serializer tests do not depend
 * on Python being installed; JsonSchemaCheck adds the real validator when
 * it is.
 */
final class PlanDocumentShape
{
    private const PROTOCOLS = ['snmp', 'gnmi', 'redfish', 'netconf', 'restconf'];
    private const REF = '/^[A-Za-z0-9][A-Za-z0-9._:\/-]*$/D';
    /** Same rule as $defs/storageFreeKey in the v2 schema. */
    private const STORAGE_KEY = '/rrd|local_data|data_source|sql|^(x-)?path$/i';

    private int $version = 1;

    /** @var list<string> */
    private array $errors = [];

    /** @return list<string> */
    public static function violations(mixed $plan): array
    {
        $shape = new self();
        $shape->plan($plan);

        return $shape->errors;
    }

    private function plan(mixed $plan): void
    {
        if (!$this->closed($plan, '', ['plan_schema_version', 'plan_id', 'generated_at', 'device_id', 'status', 'pack', 'targets', 'dedup_key'], [], true)) {
            return;
        }
        $this->check(in_array($plan->plan_schema_version, [1, 2], true), '/plan_schema_version must be 1 or 2');
        $this->version = $plan->plan_schema_version === 2 ? 2 : 1;
        if ($this->version === 2) {
            $this->storageFree($plan, '');
        }
        $this->pattern($plan->plan_id, '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/D', '/plan_id');
        $this->pattern($plan->generated_at, '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d+)?(Z|[+-]\d{2}:\d{2})$/D', '/generated_at');
        $this->check(is_int($plan->device_id) && $plan->device_id >= 1, '/device_id must be a positive integer');
        $this->check(in_array($plan->status, ['proposed', 'approved', 'active', 'superseded'], true), '/status is not a plan status');
        if ($this->closed($plan->pack, '/pack', ['name', 'version'], [], false)) {
            $this->pattern($plan->pack->name, '/^[a-z][a-z0-9]*(-[a-z0-9]+)*$/D', '/pack/name');
            $this->pattern($plan->pack->version, '/^(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)(-[0-9A-Za-z-]+(\.[0-9A-Za-z-]+)*)?(\+[0-9A-Za-z-]+(\.[0-9A-Za-z-]+)*)?$/D', '/pack/version');
        }
        $this->pattern($plan->dedup_key, '/^[0-9a-f]{64}$/D', '/dedup_key');
        $this->check(is_array($plan->targets) && $plan->targets !== [], '/targets must be a non-empty array');
        foreach (is_array($plan->targets) ? $plan->targets : [] as $i => $target) {
            $this->target($target, '/targets/' . $i);
        }
    }

    private function target(mixed $target, string $p): void
    {
        if (!$this->closed($target, $p, ['protocol', 'endpoint', 'subscription', 'metrics'], [], true)) {
            return;
        }
        $protocol = $target->protocol;
        $this->check(in_array($protocol, self::PROTOCOLS, true), $p . '/protocol is not a protocol');
        if ($this->closed($target->endpoint, $p . '/endpoint', ['credential_ref'], ['port', 'tls'], true)) {
            $endpoint = $target->endpoint;
            $this->pattern($endpoint->credential_ref, self::REF, $p . '/endpoint/credential_ref');
            $this->check(is_string($endpoint->credential_ref) && strlen($endpoint->credential_ref) <= 128, $p . '/endpoint/credential_ref is too long');
            if (property_exists($endpoint, 'port')) {
                $this->check(is_int($endpoint->port) && $endpoint->port >= 1 && $endpoint->port <= 65535, $p . '/endpoint/port is out of range');
            }
            if (property_exists($endpoint, 'tls') && $this->closed($endpoint->tls, $p . '/endpoint/tls', [], ['verify', 'ca_ref'], false)) {
                if (property_exists($endpoint->tls, 'verify')) {
                    $this->check(is_bool($endpoint->tls->verify), $p . '/endpoint/tls/verify must be a boolean');
                }
                if (property_exists($endpoint->tls, 'ca_ref')) {
                    $this->pattern($endpoint->tls->ca_ref, self::REF, $p . '/endpoint/tls/ca_ref');
                }
            }
        }
        $sp = $p . '/subscription';
        if ($this->closed($target->subscription, $sp, ['mode'], ['interval_seconds', 'paths', 'oids', 'resources'], true)) {
            $subscription = $target->subscription;
            $mode = $subscription->mode;
            $this->check(in_array($mode, ['sample', 'on_change', 'poll'], true), $sp . '/mode is not a mode');
            $hasInterval = property_exists($subscription, 'interval_seconds');
            if ($hasInterval) {
                $this->check(is_int($subscription->interval_seconds) && $subscription->interval_seconds >= 1, $sp . '/interval_seconds must be a positive integer');
            }
            $this->check($mode !== 'on_change' ? $hasInterval : !$hasInterval, $sp . '/interval_seconds is required for sample and poll and forbidden for on_change');
            foreach (['paths', 'oids', 'resources'] as $list) {
                if (property_exists($subscription, $list)) {
                    $items = $subscription->{$list};
                    $this->check(is_array($items) && $items !== [] && count(array_unique($items)) === count($items), $sp . '/' . $list . ' must be a non-empty array without repeats');
                    foreach (is_array($items) ? $items : [] as $k => $item) {
                        $this->check(is_string($item) && ($list !== 'oids' ? $item !== '' : preg_match('/^[0-9]+(\.[0-9]+)+$/D', $item) === 1), $sp . '/' . $list . '/' . $k . ' is not valid');
                    }
                }
            }
            $only = match ($protocol) {
                'snmp' => 'oids',
                'redfish' => 'resources',
                default => 'paths',
            };
            foreach (['paths', 'oids', 'resources'] as $list) {
                $this->check(property_exists($subscription, $list) === ($list === $only), $sp . ' for ' . $protocol . ' must carry ' . $only . ' and nothing else');
            }
            if ($protocol === 'snmp') {
                $this->check($mode === 'poll', $sp . '/mode must be poll for snmp');
            }
        }
        $this->check(is_array($target->metrics) && $target->metrics !== [], $p . '/metrics must be a non-empty array');
        foreach (is_array($target->metrics) ? $target->metrics : [] as $i => $metric) {
            $this->metric($metric, $p . '/metrics/' . $i);
        }
    }

    private function metric(mixed $metric, string $p): void
    {
        $optional = ['counter_bits', 'labels', 'scale', 'value_map'];
        if ($this->version === 2) {
            $optional[] = 'binding';
        }
        if (!$this->closed($metric, $p, ['id', 'type', 'unit', 'source'], $optional, true)) {
            return;
        }
        $this->pattern($metric->id, '/^[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*)+$/D', $p . '/id');
        $this->check(in_array($metric->type, ['counter', 'gauge'], true), $p . '/type is not a metric type');
        $this->check(is_string($metric->unit) && $metric->unit !== '' && strlen($metric->unit) <= 32, $p . '/unit must be 1 to 32 characters');
        $this->check(is_string($metric->source) && $metric->source !== '', $p . '/source must be a non-empty string');
        if (property_exists($metric, 'counter_bits')) {
            $this->check(in_array($metric->counter_bits, [32, 64], true), $p . '/counter_bits must be 32 or 64');
            $this->check($metric->type !== 'gauge', $p . '/counter_bits is not allowed on a gauge');
        }
        if (property_exists($metric, 'labels')) {
            $this->check(is_array($metric->labels) && count(array_unique($metric->labels)) === count($metric->labels), $p . '/labels must not repeat');
            foreach (is_array($metric->labels) ? $metric->labels : [] as $k => $label) {
                $this->pattern($label, '/^[a-z][a-z0-9_]*$/D', $p . '/labels/' . $k);
            }
        }
        if (property_exists($metric, 'scale')) {
            $this->check((is_int($metric->scale) || is_float($metric->scale)) && $metric->scale > 0, $p . '/scale must be greater than 0');
        }
        if (property_exists($metric, 'value_map')) {
            $map = $metric->value_map;
            $this->check($map instanceof \stdClass && get_object_vars($map) !== [], $p . '/value_map must be a non-empty object');
            foreach ($map instanceof \stdClass ? get_object_vars($map) : [] as $key => $value) {
                $this->check(is_int($value), $p . '/value_map/' . $key . ' must be an integer');
            }
        }
        if (property_exists($metric, 'binding') && $this->closed($metric->binding, $p . '/binding', ['binding_id', 'resource'], [], false)) {
            $this->pattern($metric->binding->binding_id, '/^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$/D', $p . '/binding/binding_id');
            if ($this->closed($metric->binding->resource, $p . '/binding/resource', ['kind', 'identity'], [], false)) {
                $this->pattern($metric->binding->resource->kind, '/^[a-z][a-z0-9_]*$/D', $p . '/binding/resource/kind');
                $this->pattern($metric->binding->resource->identity, '/^[^\x00-\x1f\x7f]+$/D', $p . '/binding/resource/identity');
            }
        }
    }

    private function storageFree(mixed $value, string $p): void
    {
        if (is_array($value)) {
            foreach ($value as $i => $item) {
                $this->storageFree($item, $p . '/' . $i);
            }
        }
        if (!$value instanceof \stdClass) {
            return;
        }
        foreach (get_object_vars($value) as $key => $item) {
            $this->check(preg_match(self::STORAGE_KEY, (string) $key) !== 1, $p . '/' . $key . ' could carry storage detail and is not allowed in a plan');
            // value_map keys are device states, not plan fields.
            if ($key !== 'value_map') {
                $this->storageFree($item, $p . '/' . $key);
            }
        }
    }

    /**
     * @param list<string> $required
     * @param list<string> $optional
     */
    private function closed(mixed $value, string $p, array $required, array $optional, bool $extensions): bool
    {
        if (!$value instanceof \stdClass) {
            $this->errors[] = ($p === '' ? '(document)' : $p) . ' must be an object';

            return false;
        }
        foreach (array_keys(get_object_vars($value)) as $key) {
            $key = (string) $key;
            $known = in_array($key, $required, true) || in_array($key, $optional, true) || ($extensions && str_starts_with($key, 'x-'));
            $this->check($known, $p . '/' . $key . ' is not allowed');
        }
        $complete = true;
        foreach ($required as $key) {
            if (!property_exists($value, $key)) {
                $this->errors[] = $p . '/' . $key . ' is required';
                $complete = false;
            }
        }

        return $complete;
    }

    private function pattern(mixed $value, string $pattern, string $p): void
    {
        $this->check(is_string($value) && preg_match($pattern, $value) === 1, $p . ' does not match ' . $pattern);
    }

    private function check(bool $condition, string $error): void
    {
        if (!$condition) {
            $this->errors[] = $error;
        }
    }
}
