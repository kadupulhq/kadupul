<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors.
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\ColorTemplates\Application\Command;

use Kadupul\ColorTemplates\Application\Port\ColorTemplateAccess;
use Kadupul\ColorTemplates\Application\Port\ColorTemplateStore;

final readonly class SaveColorTemplateItem
{
    public function __construct(private ColorTemplateAccess $access, private ColorTemplateStore $templates) {}

    public function __invoke(int $templateId, ?int $itemId, int $colorId, ?string $revision): int
    {
        return $this->templates->saveItem($this->access->authorize()->id, $templateId, $itemId, $colorId, $revision);
    }
}
