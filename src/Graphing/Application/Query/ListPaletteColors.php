<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Graphing\Application\Query;

use Kadupul\Graphing\Application\Port\PaletteColorAccess;
use Kadupul\Graphing\Application\Port\PaletteColorStore;
use Kadupul\Graphing\Domain\PaletteColorFilters;
use Kadupul\Graphing\Domain\PaletteColorPage;

final readonly class ListPaletteColors
{
    public function __construct(private PaletteColorAccess $access, private PaletteColorStore $presets) {}

    public function __invoke(PaletteColorFilters $filters): PaletteColorPage
    {
        $this->access->authorize();
        return $this->presets->list($filters);
    }
}
