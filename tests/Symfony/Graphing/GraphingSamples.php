<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Tests\Graphing;

use Kadupul\Telemetry\Contract\CollectionProtocol;
use Kadupul\Telemetry\Contract\CounterValue;
use Kadupul\Telemetry\Contract\MetricId;
use Kadupul\Telemetry\Contract\MetricType;
use Kadupul\Telemetry\Contract\ResourceId;
use Kadupul\Telemetry\Contract\Sample;
use Kadupul\Telemetry\Contract\SampleValue;
use Kadupul\Telemetry\Contract\UnknownValue;
use Kadupul\Telemetry\Contract\Unit;

final class GraphingSamples
{
    /** 2001-09-09T01:46:40Z; a round epoch keeps expected update rows readable. */
    public const EPOCH = 1000000000;

    public static function at(int $seconds, int $microseconds = 0): \DateTimeImmutable
    {
        $instant = \DateTimeImmutable::createFromFormat('U.u', sprintf('%d.%06d', $seconds, $microseconds), new \DateTimeZone('UTC'));
        if ($instant === false) {
            throw new \LogicException('Unrepresentable test instant.');
        }

        return $instant;
    }

    public static function sample(
        SampleValue $value,
        int $seconds,
        string $metric = 'interface.in_octets',
        string $identity = 'eth0',
        string $source = 'snmp',
        int $microseconds = 0,
        int $device = 42,
        CollectionProtocol $protocol = CollectionProtocol::Snmp,
    ): Sample {
        $type = $value instanceof CounterValue || $value instanceof UnknownValue ? MetricType::Counter : MetricType::Gauge;

        return new Sample(
            $device,
            $protocol,
            new MetricId($metric),
            new ResourceId('interface', $identity),
            $type,
            Unit::Octets,
            self::at($seconds, $microseconds),
            $value,
            [],
            $source,
        );
    }
}
