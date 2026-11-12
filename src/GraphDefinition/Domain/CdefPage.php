<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\GraphDefinition\Domain;

/** One page of CDEF summaries, with the total match count and the filters that produced it. */
final readonly class CdefPage
{
    /** @param list<CdefSummary> $cdefs */
    public function __construct(public array $cdefs, public int $total, public CdefFilters $filters) {}

    /** True when an earlier page exists. */
    public function hasPrevious(): bool
    {
        return $this->filters->page > 1;
    }

    /** True when more matches exist beyond this page. */
    public function hasNext(): bool
    {
        return $this->filters->page * $this->filters->rows < $this->total;
    }
}
