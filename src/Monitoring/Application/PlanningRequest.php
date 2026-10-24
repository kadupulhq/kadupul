<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Monitoring\Application;

use Kadupul\Monitoring\Domain\CapabilityDiscoveryResult;
use Kadupul\Monitoring\Domain\CollectionEndpoint;
use Kadupul\Monitoring\Domain\CollectionPlan;
use Kadupul\Monitoring\Domain\InstalledPack;
use Kadupul\Monitoring\Domain\PlanningPolicy;
use Kadupul\Telemetry\Contract\ResourceId;

final readonly class PlanningRequest
{
    /**
     * @param CapabilityDiscoveryResult $capabilities as returned by DiscoverDeviceCapabilities, with registry capabilities filled in
     * @param list<InstalledPack> $packs
     * @param list<ResourceId> $resources the device's enumerated resources; resource enumeration is a collector's job
     * @param array<string, CollectionEndpoint> $endpoints credential references by protocol, from the device's configuration
     * @param list<CollectionPlan> $currentPlans the device's stored plans, for reconciliation and deprecated packs
     */
    public function __construct(
        public int $deviceId,
        public CapabilityDiscoveryResult $capabilities,
        public array $packs,
        public array $resources,
        public array $endpoints,
        public PlanningPolicy $policy,
        public \DateTimeImmutable $generatedAt,
        public array $currentPlans = [],
    ) {
        if ($capabilities->deviceId !== $deviceId) {
            throw new \InvalidArgumentException(sprintf('Capabilities of device %d cannot plan device %d.', $capabilities->deviceId, $deviceId));
        }
    }
}
