<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Navigation\Application\Command;

use Kadupul\Navigation\Application\Port\LinkAccess;
use Kadupul\Navigation\Application\Port\LinkStore;

final readonly class ChangeLinks
{
    public function __construct(private LinkAccess $access, private LinkStore $store) {}
    public function __invoke(array $ids, string $operation, string $revision): void
    {
        $actor = $this->access->authorize();
        $this->store->mutate($actor->id, $ids, $operation, $revision);
    }
}
