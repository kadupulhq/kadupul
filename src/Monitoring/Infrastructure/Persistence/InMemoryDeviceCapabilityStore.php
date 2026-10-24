<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Monitoring\Infrastructure\Persistence;

use Kadupul\Monitoring\Application\Port\DeviceCapabilityStore;
use Kadupul\Monitoring\Domain\Capability;

final class InMemoryDeviceCapabilityStore implements DeviceCapabilityStore
{
    /** @var array<int, array<string, Capability>> */
    private array $byDevice = [];

    public function capabilitiesFor(int $deviceId): array
    {
        return array_values($this->byDevice[$deviceId] ?? []);
    }

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
