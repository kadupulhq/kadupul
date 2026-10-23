<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Tests\Monitoring\Fakes;

use Kadupul\Monitoring\Application\Port\ExistingMonitoring;
use Kadupul\Monitoring\Domain\MonitoredSeries;
use Kadupul\Telemetry\Contract\MetricId;
use Kadupul\Telemetry\Contract\ResourceId;

/**
 * What is already monitored, loaded from a fixture. It has no method that
 * could change anything, and it records every call so a test can show the
 * planner only asked.
 */
final class RecordingExistingMonitoring implements ExistingMonitoring
{
    /** @var list<string> */
    public array $calls = [];

    /** @param array<int, list<MonitoredSeries>> $series */
    public function __construct(private readonly array $series = []) {}

    /**
     * @param string $path JSON fixture: {"<device id>": [{"resource": "interface/eth0", "metric": "...", "origin": "...", "protocol": "..."}]}
     */
    public static function fromFixture(string $path): self
    {
        $series = [];
        foreach (json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR) as $deviceId => $entries) {
            foreach ($entries as $entry) {
                [$kind, $identity] = explode('/', $entry['resource'], 2);
                $series[(int) $deviceId][] = new MonitoredSeries(new ResourceId($kind, $identity), new MetricId($entry['metric']), $entry['origin'], $entry['protocol']);
            }
        }

        return new self($series);
    }

    public function monitoredSeries(int $deviceId): array
    {
        $this->calls[] = 'monitoredSeries(' . $deviceId . ')';

        return $this->series[$deviceId] ?? [];
    }
}
