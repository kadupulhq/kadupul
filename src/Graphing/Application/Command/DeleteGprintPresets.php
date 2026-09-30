<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Graphing\Application\Command;

use Kadupul\Graphing\Application\Port\GprintPresetAccess;
use Kadupul\Graphing\Application\Port\GprintPresetStore;

final readonly class DeleteGprintPresets
{
    public function __construct(private GprintPresetAccess $access, private GprintPresetStore $presets) {}

    /** @param list<int> $ids */
    public function __invoke(array $ids): void
    {
        $actor = $this->access->authorize();
        $this->presets->delete($actor->id, $ids);
    }
}
