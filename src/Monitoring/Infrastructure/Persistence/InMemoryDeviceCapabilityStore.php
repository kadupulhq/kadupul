<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Monitoring\Infrastructure\Persistence;

use Kadupul\Monitoring\Application\Port\DeviceCapabilityStore;
use Kadupul\Monitoring\Domain\Capability;

/** Holds device capabilities for one process; lost when it ends. */
final class InMemoryDeviceCapabilityStore implements DeviceCapabilityStore
{
    /** @var array<int, array<string, Capability>> */
    private array $byDevice = [];

    /** The device's capabilities, ordered by key. */
    public function capabilitiesFor(int $deviceId): array
    {
        return array_values($this->byDevice[$deviceId] ?? []);
    }

    /** Replaces the device's whole capability set. */
    public function replace(int $deviceId, array $capabilities): void
    {
        $keyed = [];
        foreach ($capabilities as $capability) {
            $keyed[$capability->key()] = $capability;
        }
        ksort($keyed, SORT_STRING);
        $this->byDevice[$deviceId] = $keyed;
    }
}
