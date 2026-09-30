<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Graphing\Application\Command;

use Kadupul\Graphing\Application\Port\GprintPresetAccess;
use Kadupul\Graphing\Application\Port\GprintPresetStore;

final readonly class SaveGprintPreset
{
    public function __construct(private GprintPresetAccess $access, private GprintPresetStore $presets) {}

    public function __invoke(?int $id, string $name, string $gprintText, ?string $revision): int
    {
        $actor = $this->access->authorize();
        return $this->presets->save($actor->id, $id, $name, $gprintText, $revision);
    }
}
