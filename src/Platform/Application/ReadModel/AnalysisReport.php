<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Application\ReadModel;

final readonly class AnalysisReport
{
    /** @param list<TableAnalysis> $tables */
    public function __construct(public bool $main, public bool $noBinlog, public array $tables, public int $seconds) {}

    public function failed(): int
    {
        return count(array_filter($this->tables, static fn(TableAnalysis $table): bool => !$table->succeeded()));
    }
}
