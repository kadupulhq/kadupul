<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Navigation\Application\Command;

use Kadupul\Navigation\Application\Port\LinkAccess;
use Kadupul\Navigation\Application\Port\LinkStore;

final readonly class SaveLink
{
    public function __construct(private LinkAccess $access, private LinkStore $store) {}
    public function __invoke(?int $id, array $fields, string $revision): int
    {
        $actor = $this->access->authorize();
        return $this->store->save($actor->id, $id, $fields, $revision);
    }
}
