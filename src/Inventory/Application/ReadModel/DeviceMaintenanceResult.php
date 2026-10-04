<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Inventory\Application\ReadModel;

final readonly class DeviceMaintenanceResult
{
    public function __construct(public bool $completed, public string $message, public string $output = '') {}
}
