<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Monitoring\Infrastructure\Serialization;

use Kadupul\Monitoring\Domain\CollectionPlan;
use Kadupul\Monitoring\Domain\PlannedMetric;
use Kadupul\Monitoring\Domain\PlanTarget;

/**
 * Writes a plan as one UTF-8 JSON document, the form a collector in any
 * language reads. A plan with no metric bindings is written in
 * plan_schema_version 1 (docs/schemas/collection-plan.schema.json), so
 * existing collectors keep reading it; a plan with any binding is written in
 * version 2 (collection-plan.v2.schema.json). Optional fields are omitted
 * rather than written as null, because neither schema has nullable fields.
 */
final class PlanSerializer
{
    public const PLAN_SCHEMA_VERSION = 1;
    public const BOUND_PLAN_SCHEMA_VERSION = 2;

    public function toJson(CollectionPlan $plan): string
    {
        return json_encode($this->toDocument($plan), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    }

    /** @return array<string, mixed> */
    public function toDocument(CollectionPlan $plan): array
    {
        return [
            'plan_schema_version' => $this->bound($plan) ? self::BOUND_PLAN_SCHEMA_VERSION : self::PLAN_SCHEMA_VERSION,
            'plan_id' => $plan->planId,
            'generated_at' => $plan->generatedAt->format('Y-m-d\TH:i:s\Z'),
            'device_id' => $plan->deviceId,
            'status' => $plan->status->value,
            'pack' => ['name' => $plan->packName, 'version' => $plan->packVersion],
            'targets' => array_map($this->target(...), $plan->targets),
            'dedup_key' => $plan->dedupKey,
        ];
    }

    private function bound(CollectionPlan $plan): bool
    {
        foreach ($plan->targets as $target) {
            foreach ($target->metrics as $metric) {
                if ($metric->binding !== null) {
                    return true;
                }
            }
        }

        return false;
    }

    /** @return array<string, mixed> */
    private function target(PlanTarget $target): array
    {
        $endpoint = ['credential_ref' => $target->endpoint->credentialRef];
        if ($target->endpoint->port !== null) {
            $endpoint['port'] = $target->endpoint->port;
        }
        $tls = [];
        if ($target->endpoint->tlsVerify !== null) {
            $tls['verify'] = $target->endpoint->tlsVerify;
        }
        if ($target->endpoint->caRef !== null) {
            $tls['ca_ref'] = $target->endpoint->caRef;
        }
        if ($tls !== []) {
            $endpoint['tls'] = $tls;
        }
        $subscription = ['mode' => $target->mode];
        if ($target->intervalSeconds !== null) {
            $subscription['interval_seconds'] = $target->intervalSeconds;
        }
        $subscription[match ($target->protocol) {
            'snmp' => 'oids',
            'redfish' => 'resources',
            default => 'paths',
        }] = $target->subscriptions;

        return [
            'protocol' => $target->protocol,
            'endpoint' => $endpoint,
            'subscription' => $subscription,
            'metrics' => array_map($this->metric(...), $target->metrics),
        ];
    }

    /** @return array<string, mixed> */
    private function metric(PlannedMetric $metric): array
    {
        $document = [
            'id' => $metric->id->toString(),
            'type' => $metric->type->value,
            'unit' => $metric->unit,
            'source' => $metric->source,
        ];
        if ($metric->counterBits !== null) {
            $document['counter_bits'] = $metric->counterBits;
        }
        if ($metric->labels !== []) {
            $document['labels'] = $metric->labels;
        }
        if ($metric->scale !== null) {
            $document['scale'] = $metric->scale;
        }
        if ($metric->valueMap !== null) {
            // An object even when every key looks numeric, which PHP would otherwise encode as a list.
            $document['value_map'] = (object) $metric->valueMap;
        }
        if ($metric->binding !== null) {
            $document['binding'] = [
                'binding_id' => $metric->binding->bindingId,
                'resource' => ['kind' => $metric->binding->resource->kind, 'identity' => $metric->binding->resource->identity],
            ];
        }

        return $document;
    }
}
