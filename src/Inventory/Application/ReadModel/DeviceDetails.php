<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Inventory\Application\ReadModel;

final readonly class DeviceDetails
{
    public function __construct(public DeviceSummary $device, public string $notes, public int $siteId, public ?string $siteName) {}
}
