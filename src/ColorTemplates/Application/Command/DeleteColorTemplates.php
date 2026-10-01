<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors.
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\ColorTemplates\Application\Command;

use Kadupul\ColorTemplates\Application\Port\ColorTemplateAccess;
use Kadupul\ColorTemplates\Application\Port\ColorTemplateStore;

final readonly class DeleteColorTemplates
{
    public function __construct(private ColorTemplateAccess $access, private ColorTemplateStore $templates) {}

    public function __invoke(array $ids, array $revisions): void
    {
        $this->templates->delete($this->access->authorize()->id, $ids, $revisions);
    }
}
