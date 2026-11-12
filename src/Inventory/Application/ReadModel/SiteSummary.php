<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Inventory\Application\ReadModel;

/**
 * One row of the site list.
 */
final readonly class SiteSummary
{
    /**
     * Holds the row values.
     *
     * @param int $visibleDevices Number of the site's devices the current user can see.
     */
    public function __construct(public int $id, public string $name, public string $city, public string $state, public string $country, public int $visibleDevices) {}
}
