<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors.
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\ColorTemplates\Application\Command;

use Kadupul\ColorTemplates\Application\Port\ColorTemplateAccess;
use Kadupul\ColorTemplates\Application\Port\ColorTemplateSynchronizer;

final readonly class SyncColorTemplate
{
    public function __construct(private ColorTemplateAccess $access, private ColorTemplateSynchronizer $sync) {}

    public function __invoke(int $templateId): array
    {
        return $this->sync->sync($this->access->authorize()->id, $templateId);
    }
}
