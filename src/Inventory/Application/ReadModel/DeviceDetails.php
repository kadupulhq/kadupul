<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Inventory\Application\ReadModel;

/**
 * The device summary plus its notes and the site it belongs to, for the device detail view.
 */
final readonly class DeviceDetails
{
    /**
     * Holds the detail values.
     *
     * @param ?string $siteName Null when the device has no site.
     */
    public function __construct(public DeviceSummary $device, public string $notes, public int $siteId, public ?string $siteName) {}
}
