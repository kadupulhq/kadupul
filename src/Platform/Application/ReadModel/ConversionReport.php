<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Application\ReadModel;

final readonly class ConversionReport
{
    /** @param list<array{name: string, result: TableResult, rows: ?int, statement: ?string}> $tables */
    public function __construct(
        public bool $main,
        public ConversionOutcome $outcome,
        public ?string $missingSkipTable,
        public array $tables,
        public bool $dryRun,
    ) {}

    public static function stopped(bool $main, ConversionOutcome $outcome, bool $dryRun, ?string $missingSkipTable = null): self
    {
        return new self($main, $outcome, $missingSkipTable, [], $dryRun);
    }

    public function failed(): int
    {
        return count(array_filter($this->tables, static fn(array $table): bool => $table['result'] === TableResult::Failed));
    }
}
