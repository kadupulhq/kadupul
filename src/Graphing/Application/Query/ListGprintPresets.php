<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Graphing\Application\Query;

use Kadupul\Graphing\Application\Port\GprintPresetAccess;
use Kadupul\Graphing\Application\Port\GprintPresetStore;
use Kadupul\Graphing\Domain\GprintPresetFilters;
use Kadupul\Graphing\Domain\GprintPresetPage;

final readonly class ListGprintPresets
{
    public function __construct(private GprintPresetAccess $access, private GprintPresetStore $presets) {}

    public function __invoke(GprintPresetFilters $filters): GprintPresetPage
    {
        $this->access->authorize();
        return $this->presets->list($filters);
    }
}
