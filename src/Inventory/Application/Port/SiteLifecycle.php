<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Inventory\Application\Port;

use Kadupul\Inventory\Domain\SiteSelection;

interface SiteLifecycle
{
    /** @return list<\Kadupul\Inventory\Domain\Site> */
    public function find(array $ids): array;
    public function delete(int $userId, SiteSelection $selection): void;
    /** @return list<int> */
    public function duplicate(int $userId, SiteSelection $selection, string $pattern): array;
}
