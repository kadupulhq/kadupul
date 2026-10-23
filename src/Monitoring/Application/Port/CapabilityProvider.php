<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Monitoring\Application\Port;

use Kadupul\Monitoring\Domain\CapabilityDiscoveryResult;

/**
 * Reads what one protocol says a device supports. An adapter must be
 * read-only: no set, edit-config or other write reaches the device.
 *
 * No adapter exists yet; protocol clients are future work (ADR 0003).
 */
interface CapabilityProvider
{
    /** The protocol every capability this provider reports is tagged with. */
    public function protocol(): string;

    /**
     * Throws when the device cannot be asked at all. A partial answer is
     * returned with its errors so the models it did report are still used.
     */
    public function discoverCapabilities(int $deviceId): CapabilityDiscoveryResult;
}
