<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Graphing\Domain;

final readonly class GprintPresetPage
{
    /** @param list<GprintPreset> $presets */
    public function __construct(public array $presets, public int $total, public GprintPresetFilters $filters) {}

    public function hasPrevious(): bool
    {
        return $this->filters->page > 1;
    }

    public function hasNext(): bool
    {
        return $this->filters->page * $this->filters->rows < $this->total;
    }
}
