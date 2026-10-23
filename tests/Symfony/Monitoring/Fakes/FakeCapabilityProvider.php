<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Tests\Monitoring\Fakes;

use Kadupul\Monitoring\Application\Port\CapabilityProvider;
use Kadupul\Monitoring\Domain\Capability;
use Kadupul\Monitoring\Domain\CapabilityDiscoveryResult;
use Kadupul\Monitoring\Domain\CapabilityStatus;
use Kadupul\Monitoring\Domain\ModelReference;

/**
 * Stands in for a protocol's capability exchange. It answers from what a test
 * told it the device reports and never talks to a device.
 */
abstract class FakeCapabilityProvider implements CapabilityProvider
{
    /** @var array<int, array<string, Capability>> */
    private array $reports = [];
    private ?\Exception $failure = null;
    public int $calls = 0;

    abstract protected function family(): string;

    public function advertise(int $deviceId, string $namespace, string $name, string $revision): void
    {
        $this->report($deviceId, $namespace, $name, $revision, CapabilityStatus::Advertised);
    }

    public function verify(int $deviceId, string $namespace, string $name, string $revision): void
    {
        $this->report($deviceId, $namespace, $name, $revision, CapabilityStatus::Verified);
    }

    public function report(int $deviceId, string $namespace, string $name, string $revision, CapabilityStatus $status, ?string $reason = null): void
    {
        $model = new ModelReference($this->family(), $namespace, $name, $revision);
        $this->reports[$deviceId][$model->key()] = new Capability($model, $this->protocol(), $status, $reason);
    }

    public function withdraw(int $deviceId, string $namespace, string $name): void
    {
        foreach (array_keys($this->reports[$deviceId] ?? []) as $key) {
            if (str_starts_with($key, $this->family() . '|' . $namespace . '|' . $name . '@')) {
                unset($this->reports[$deviceId][$key]);
            }
        }
    }

    public function failWith(?\Exception $failure): void
    {
        $this->failure = $failure;
    }

    public function discoverCapabilities(int $deviceId): CapabilityDiscoveryResult
    {
        $this->calls++;
        if ($this->failure !== null) {
            throw $this->failure;
        }

        return new CapabilityDiscoveryResult($deviceId, array_values($this->reports[$deviceId] ?? []));
    }
}
