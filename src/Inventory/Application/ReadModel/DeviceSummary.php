<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Inventory\Application\ReadModel;

/**
 * One row of the device list.
 */
final readonly class DeviceSummary
{
    /**
     * Holds the row values.
     *
     * @param string $status One of "Unknown", "Down", "Recovering", "Up" or "Error".
     */
    public function __construct(public int $id, public string $description, public string $hostname, public bool $disabled, public string $status, public string $location, public string $externalId) {}
}
