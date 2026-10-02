<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Graphing\Application\Query;

use Kadupul\Graphing\Application\Port\PaletteColorAccess;
use Kadupul\Graphing\Application\Port\PaletteColorStore;
use Kadupul\Graphing\Domain\PaletteColor;

final readonly class FindPaletteColor
{
    public function __construct(private PaletteColorAccess $access, private PaletteColorStore $presets) {}

    public function __invoke(int $id): ?PaletteColor
    {
        $this->access->authorize();
        return $this->presets->find($id);
    }
}
