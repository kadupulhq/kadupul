<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Monitoring\Application\Port;

use Kadupul\Monitoring\Domain\Capability;

/**
 * Holds capabilities as providers reported them, before registry assessment,
 * so a later registry change is applied on the next discovery instead of
 * being frozen into stored state.
 */
interface DeviceCapabilityStore
{
    /** @return list<Capability> */
    public function capabilitiesFor(int $deviceId): array;

    /** @param list<Capability> $capabilities the complete set for the device */
    public function replace(int $deviceId, array $capabilities): void;
}
