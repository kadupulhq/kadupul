<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors.
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\ColorTemplates\Domain;

final readonly class ColorTemplatePage
{
    /** @param list<ColorTemplate> $templates */
    public function __construct(public array $templates, public int $total, public ColorTemplateFilters $filters) {}

    public function hasPrevious(): bool
    {
        return $this->filters->page > 1;
    }

    public function hasNext(): bool
    {
        return $this->filters->page * $this->filters->rows < $this->total;
    }
}
