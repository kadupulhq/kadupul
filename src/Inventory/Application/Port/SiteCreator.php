<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Inventory\Application\Port;

use Kadupul\Inventory\Domain\NewSite;

/**
 * Stores new sites.
 */
interface SiteCreator
{
    /**
     * Creates the site and returns its id.
     */
    public function create(int $userId, NewSite $site): int;
}
