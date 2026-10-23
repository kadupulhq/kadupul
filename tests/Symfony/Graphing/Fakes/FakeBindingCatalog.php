<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Tests\Graphing\Fakes;

use Kadupul\Monitoring\Contract\BindingCatalog;
use Kadupul\Monitoring\Contract\BindingResolution;
use Kadupul\Monitoring\Contract\ResolutionStatus;
use Kadupul\Monitoring\Contract\RrdDataSourceType;
use Kadupul\Monitoring\Contract\StorageReference;
use Kadupul\Monitoring\Contract\WriterOwnership;
use Kadupul\Telemetry\Contract\MetricId;
use Kadupul\Telemetry\Contract\ResourceId;

/**
 * Bound series resolve only for their exact device, protocol, metric and
 * resource. A scripted answer, keyed by metric and resource alone, takes
 * precedence so a test can return a resolution for a different series than
 * was asked for. Anything else has no binding.
 */
final class FakeBindingCatalog implements BindingCatalog
{
    /** @var array<string, BindingResolution> */
    private array $answers = [];

    /** @var array<string, BindingResolution> */
    private array $bound = [];

    /** @var list<array{int, string, string, string}> */
    public array $calls = [];

    public function bind(
        int $deviceId,
        string $metric,
        string $identity,
        string $dataSourceName,
        RrdDataSourceType $type,
        string $storage,
        WriterOwnership $ownership = WriterOwnership::ModelDriven,
        string $protocol = 'snmp',
    ): void {
        $this->bound[self::key($deviceId, $protocol, $metric, $identity)] = new BindingResolution(
            ResolutionStatus::Resolved,
            $deviceId,
            new MetricId($metric),
            new ResourceId('interface', $identity),
            $protocol,
            'scripted',
            'binding-' . $metric,
            'openconfig-interfaces',
            '1.0.0',
            $ownership,
            $dataSourceName,
            $type,
            new StorageReference($storage),
        );
    }

    /** Scripts any answer, including one that names a different series than was asked for. */
    public function answer(string $metric, string $identity, BindingResolution $resolution): void
    {
        $this->answers[$metric . "\n" . $identity] = $resolution;
    }

    public function resolve(int $deviceId, MetricId $metric, ResourceId $resource, string $protocol): BindingResolution
    {
        $this->calls[] = [$deviceId, $metric->toString(), $resource->toString(), $protocol];

        return $this->answers[$metric->toString() . "\n" . $resource->identity]
            ?? $this->bound[self::key($deviceId, $protocol, $metric->toString(), $resource->identity)]
            ?? new BindingResolution(ResolutionStatus::NoBinding, $deviceId, $metric, $resource, $protocol, 'scripted: none');
    }

    private static function key(int $deviceId, string $protocol, string $metric, string $identity): string
    {
        return $deviceId . "\n" . $protocol . "\n" . $metric . "\n" . $identity;
    }
}
