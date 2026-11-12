<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Inventory\Application\ReadModel;

/**
 * A site's id and name, as offered in the device list's site filter.
 */
final readonly class DeviceSite
{
    /**
     * Holds the site's id and name.
     */
    public function __construct(public int $id, public string $name) {}
}
