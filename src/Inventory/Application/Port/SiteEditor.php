<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Inventory\Application\Port;

use Kadupul\Inventory\Domain\Site;

/**
 * Loads and saves sites for editing.
 */
interface SiteEditor
{
    /**
     * Loads the site, or returns null when it does not exist.
     */
    public function find(int $id): ?Site;
    /**
     * Stores the edited site.
     *
     * @param string $expectedRevision Token from the site when it was loaded.
     */
    public function save(int $userId, Site $site, string $expectedRevision): void;
}
