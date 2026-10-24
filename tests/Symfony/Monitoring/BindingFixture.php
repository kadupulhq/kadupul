<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Tests\Monitoring;

use Kadupul\Monitoring\Domain\BindingKey;
use Kadupul\Monitoring\Domain\BindingOrigin;
use Kadupul\Monitoring\Domain\BindingProvenance;
use Kadupul\Monitoring\Domain\BindingStatus;
use Kadupul\Monitoring\Domain\MetricBinding;
use Kadupul\Monitoring\Domain\RrdDataSourceName;
use Kadupul\Monitoring\Domain\RrdDataSourceType;
use Kadupul\Monitoring\Domain\StorageField;
use Kadupul\Telemetry\Contract\MetricId;
use Kadupul\Telemetry\Contract\ResourceId;

final class BindingFixture
{
    public static function key(int $deviceId = 42, string $metric = 'interface.in_octets', string $identity = 'eth0', string $protocol = 'snmp'): BindingKey
    {
        return new BindingKey($deviceId, new MetricId($metric), new ResourceId('interface', $identity), $protocol);
    }

    public static function field(int $localDataId = 7, string $name = 'traffic_in'): StorageField
    {
        return new StorageField($localDataId, new RrdDataSourceName($name));
    }

    public static function binding(
        ?BindingKey $key = null,
        ?StorageField $field = null,
        RrdDataSourceType $type = RrdDataSourceType::Counter,
        string $packVersion = '1.0.0',
        ?BindingProvenance $provenance = null,
    ): MetricBinding {
        return new MetricBinding(
            $key ?? self::key(),
            'openconfig-interfaces',
            $packVersion,
            $field ?? self::field(),
            $type,
            BindingStatus::Proposed,
            $provenance ?? new BindingProvenance(BindingOrigin::LegacyDiscovered, 'data source 7', new \DateTimeImmutable('2026-10-23T14:05:00Z')),
            null,
        );
    }
}
