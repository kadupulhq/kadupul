<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors.
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\ColorTemplates\Application\Query;

use Kadupul\ColorTemplates\Application\Port\ColorTemplateStore;
use Kadupul\ColorTemplates\Domain\ColorTemplateFilters;
use Kadupul\ColorTemplates\Domain\ColorTemplatePage;

final readonly class ListColorTemplates
{
    public function __construct(private ColorTemplateStore $templates) {}

    public function __invoke(ColorTemplateFilters $filters): ColorTemplatePage
    {
        return $this->templates->list($filters);
    }
}
