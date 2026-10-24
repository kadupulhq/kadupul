<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Monitoring\Domain;

final readonly class PlanTarget
{
    /**
     * @param string $mode sample, on_change or poll
     * @param list<string> $subscriptions paths, OIDs or Redfish resources, by protocol
     * @param list<PlannedMetric> $metrics
     */
    public function __construct(
        public string $protocol,
        public CollectionEndpoint $endpoint,
        public string $mode,
        public ?int $intervalSeconds,
        public array $subscriptions,
        public array $metrics,
    ) {
        if (!in_array($protocol, Capability::PROTOCOLS, true)) {
            throw new InvalidCollectionPlan(sprintf('Unknown protocol "%s".', $protocol));
        }
        if (!in_array($mode, ['sample', 'on_change', 'poll'], true)) {
            throw new InvalidCollectionPlan(sprintf('Unknown subscription mode "%s".', $mode));
        }
        if (($mode === 'on_change') !== ($intervalSeconds === null)) {
            throw new InvalidCollectionPlan('Sample and poll subscriptions need an interval; on_change subscriptions must not have one.');
        }
        if ($protocol === 'snmp' && $mode !== 'poll') {
            throw new InvalidCollectionPlan('SNMP targets are polled.');
        }
        if ($subscriptions === [] || $metrics === []) {
            throw new InvalidCollectionPlan(sprintf('A %s target needs at least one subscription and one metric.', $protocol));
        }
    }

    /** @return list<mixed> */
    public function canonical(): array
    {
        return [$this->protocol, $this->endpoint->canonical(), $this->mode, $this->intervalSeconds, $this->subscriptions, array_map(static fn(PlannedMetric $m): array => $m->canonical(), $this->metrics)];
    }
}
