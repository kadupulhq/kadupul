<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Navigation\Application\Query;

use Kadupul\Navigation\Application\Port\LinkAccess;
use Kadupul\Navigation\Application\Port\LinkStore;

final readonly class ListLinks
{
    public function __construct(private LinkAccess $access, private LinkStore $store) {}
    public function __invoke(?array $filters = null): array
    {
        $this->access->authorize();
        return $filters === null ? $this->store->snapshot() : $this->store->list($filters);
    }
}
