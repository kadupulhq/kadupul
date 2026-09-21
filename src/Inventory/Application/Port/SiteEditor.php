<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Inventory\Application\Port;

use Kadupul\Inventory\Domain\Site;

interface SiteEditor
{
    public function find(int $id): ?Site;
    public function save(int $userId, Site $site, string $expectedRevision): void;
}
