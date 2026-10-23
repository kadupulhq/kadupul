<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Tests\Monitoring;

use Kadupul\Monitoring\Application\PlanningRequest;
use Kadupul\Monitoring\Domain\Capability;
use Kadupul\Monitoring\Domain\CapabilityDiscoveryResult;
use Kadupul\Monitoring\Domain\CapabilityStatus;
use Kadupul\Monitoring\Domain\CollectionEndpoint;
use Kadupul\Monitoring\Domain\CollectionPlan;
use Kadupul\Monitoring\Domain\InstalledPack;
use Kadupul\Monitoring\Domain\ModelReference;
use Kadupul\Monitoring\Domain\PackState;
use Kadupul\Monitoring\Domain\PackValidator;
use Kadupul\Monitoring\Domain\PlanningPolicy;
use Kadupul\Telemetry\Contract\ResourceId;

final class PlanningFixture
{
    public const GENERATED_AT = '2026-10-23T14:05:00Z';

    public static function pack(?\stdClass $document = null, PackState $state = PackState::Installed): InstalledPack
    {
        return new InstalledPack((new PackValidator('1.2.35'))->parse($document ?? PackDocuments::example(), 'test.pack.json'), $state);
    }

    public static function openConfig(CapabilityStatus $status = CapabilityStatus::Verified, ?string $reason = null): Capability
    {
        return new Capability(new ModelReference('openconfig', RegistryFixture::OC_INTERFACES_NS, 'openconfig-interfaces', RegistryFixture::OC_INTERFACES_REV), 'gnmi', $status, $reason, ['openconfig-interfaces']);
    }

    public static function ifMib(CapabilityStatus $status = CapabilityStatus::Verified, ?string $reason = null): Capability
    {
        return new Capability(new ModelReference('snmp-mib', RegistryFixture::IF_MIB_NS, 'IF-MIB', RegistryFixture::IF_MIB_REV), 'snmp', $status, $reason, ['snmp:if-mib']);
    }

    /** @return array<string, CollectionEndpoint> */
    public static function endpoints(): array
    {
        return [
            'gnmi' => new CollectionEndpoint('gnmi/core-sw-01', 9339, true),
            'snmp' => new CollectionEndpoint('snmp/core-sw-01'),
        ];
    }

    /** @return list<ResourceId> */
    public static function interfaces(string ...$names): array
    {
        return array_map(static fn(string $n): ResourceId => new ResourceId('interface', $n), $names === [] ? ['eth0', 'eth1'] : $names);
    }

    /**
     * @param list<Capability> $capabilities
     * @param list<InstalledPack>|null $packs
     * @param list<ResourceId>|null $resources
     * @param array<string, CollectionEndpoint>|null $endpoints
     * @param list<CollectionPlan> $currentPlans
     */
    public static function request(
        array $capabilities,
        ?array $packs = null,
        ?PlanningPolicy $policy = null,
        ?array $resources = null,
        ?array $endpoints = null,
        array $currentPlans = [],
        int $deviceId = 42,
        string $generatedAt = self::GENERATED_AT,
    ): PlanningRequest {
        return new PlanningRequest(
            $deviceId,
            new CapabilityDiscoveryResult($deviceId, $capabilities),
            $packs ?? [self::pack()],
            $resources ?? [...self::interfaces(), new ResourceId('power_supply', 'psu1')],
            $endpoints ?? self::endpoints(),
            $policy ?? PlanningPolicy::verifiedOnly(),
            new \DateTimeImmutable($generatedAt),
            $currentPlans,
        );
    }
}
