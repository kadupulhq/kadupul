<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Inventory\Application\Port;

use Kadupul\Inventory\Domain\SiteSelection;

/**
 * Looks up sites and deletes or duplicates them in batches.
 */
interface SiteLifecycle
{
    /** @return list<\Kadupul\Inventory\Domain\Site> */
    public function find(array $ids): array;
    /**
     * Deletes every site in the selection.
     */
    public function delete(int $userId, SiteSelection $selection): void;
    /** @return list<int> */
    public function duplicate(int $userId, SiteSelection $selection, string $pattern): array;
}
