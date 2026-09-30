<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Graphing\Application\Query;

use Kadupul\Graphing\Application\Port\GprintPresetAccess;
use Kadupul\Graphing\Application\Port\GprintPresetStore;

final readonly class FindGprintPresets
{
    public function __construct(private GprintPresetAccess $access, private GprintPresetStore $presets) {}

    public function __invoke(array $ids): array
    {
        $this->access->authorize();
        return $this->presets->findMany($ids);
    }
}
