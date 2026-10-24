<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Tests\Telemetry;

use Kadupul\Monitoring\Domain\Capability;
use Kadupul\Telemetry\Contract\CollectionProtocol;
use Kadupul\Telemetry\Contract\InvalidTelemetryValue;
use PHPUnit\Framework\TestCase;

final class CollectionProtocolTest extends TestCase
{
    private const PLAN_SCHEMA = __DIR__ . '/../../../docs/schemas/collection-plan.v2.schema.json';

    public function testCasesMatchTheProtocolsACollectionPlanTargetMayName(): void
    {
        $schema = json_decode((string) file_get_contents(self::PLAN_SCHEMA), true, 512, JSON_THROW_ON_ERROR);
        $planned = $schema['$defs']['target']['properties']['protocol']['enum'];

        self::assertSame($planned, self::values());
    }

    public function testCasesMatchTheProtocolsMonitoringDiscoversCapabilitiesFor(): void
    {
        self::assertSame(Capability::PROTOCOLS, self::values());
    }

    public function testFromNameAcceptsEveryPlannedProtocol(): void
    {
        foreach (CollectionProtocol::cases() as $protocol) {
            self::assertSame($protocol, CollectionProtocol::fromName($protocol->value));
        }
    }

    public function testFromNameRejectsAnUnknownProtocolAndListsTheKnownOnes(): void
    {
        $this->expectException(InvalidTelemetryValue::class);
        $this->expectExceptionMessage('Unknown collection protocol "SNMP"; expected one of snmp, gnmi, redfish, netconf, restconf.');

        CollectionProtocol::fromName('SNMP');
    }

    /** @return list<string> */
    private static function values(): array
    {
        return array_map(static fn(CollectionProtocol $p): string => $p->value, CollectionProtocol::cases());
    }
}
