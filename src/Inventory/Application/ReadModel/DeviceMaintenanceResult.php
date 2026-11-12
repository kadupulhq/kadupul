<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Inventory\Application\ReadModel;

/**
 * The outcome of a device maintenance action.
 */
final readonly class DeviceMaintenanceResult
{
    /**
     * Holds the outcome.
     *
     * @param bool $completed Whether the action ran to completion.
     * @param string $output Any text the action produced; empty when there is none.
     */
    public function __construct(public bool $completed, public string $message, public string $output = '') {}
}
