<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Graphing\Application\Command;

use Kadupul\Graphing\Application\Port\PaletteColorAccess;
use Kadupul\Graphing\Application\Port\PaletteColorStore;

final readonly class SavePaletteColor
{
    public function __construct(private PaletteColorAccess $access, private PaletteColorStore $presets) {}

    public function __invoke(?int $id, string $name, string $hex, ?string $revision): int
    {
        $actor = $this->access->authorize();
        return $this->presets->save($actor->id, $id, $name, $hex, $revision);
    }
}
