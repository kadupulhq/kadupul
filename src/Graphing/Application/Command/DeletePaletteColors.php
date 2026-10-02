<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Graphing\Application\Command;

use Kadupul\Graphing\Application\Port\PaletteColorAccess;
use Kadupul\Graphing\Application\Port\PaletteColorStore;

final readonly class DeletePaletteColors
{
    public function __construct(private PaletteColorAccess $access, private PaletteColorStore $presets) {}

    /** @param list<int> $ids */
    public function __invoke(array $ids, array $revisions): void
    {
        $actor = $this->access->authorize();
        $this->presets->delete($actor->id, $ids, $revisions);
    }
}
