<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Tests\Telemetry;

use Kadupul\Telemetry\Contract\InvalidTelemetryValue;
use Kadupul\Telemetry\Contract\MetricId;
use Kadupul\Telemetry\Contract\ResourceId;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class IdentifiersTest extends TestCase
{
    public function testMetricIdIsDottedLowercase(): void
    {
        $id = new MetricId('interface.in_octets');

        self::assertSame('interface.in_octets', $id->toString());
        self::assertTrue($id->equals(new MetricId('interface.in_octets')));
        self::assertFalse($id->equals(new MetricId('interface.out_octets')));
    }

    #[DataProvider('invalidMetricIds')]
    public function testInvalidMetricIdIsRejected(string $text): void
    {
        $this->expectException(InvalidTelemetryValue::class);

        new MetricId($text);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidMetricIds(): iterable
    {
        yield 'empty' => [''];
        yield 'uppercase' => ['Interface.in_octets'];
        yield 'leading dot' => ['.in_octets'];
        yield 'trailing dot' => ['interface.'];
        yield 'double dot' => ['interface..in_octets'];
        yield 'space' => ['interface in_octets'];
        yield 'trailing newline' => ["interface.in_octets\n"];
    }

    public function testResourceIdCombinesKindAndIdentity(): void
    {
        $id = new ResourceId('interface', 'eth0');

        self::assertSame('interface/eth0', $id->toString());
        self::assertSame('interface', $id->kind);
        self::assertSame('eth0', $id->identity);
        self::assertTrue($id->equals(new ResourceId('interface', 'eth0')));
        self::assertFalse($id->equals(new ResourceId('interface', 'eth1')));
    }

    public function testResourceIdentityMayContainSlashes(): void
    {
        self::assertSame('interface/GigabitEthernet0/0/1', (new ResourceId('interface', 'GigabitEthernet0/0/1'))->toString());
    }

    public function testResourceIdRejectsBadKindAndEmptyIdentity(): void
    {
        foreach ([['Interface', 'eth0'], ['', 'eth0'], ['interface', ''], ['interface', "eth0\0"]] as [$kind, $identity]) {
            try {
                new ResourceId($kind, $identity);
                self::fail('Expected rejection of ' . json_encode([$kind, $identity]));
            } catch (InvalidTelemetryValue) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
