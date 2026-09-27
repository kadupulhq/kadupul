<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Application\Port;

use Kadupul\Platform\Domain\Schema\TableChange;

interface TableConversion
{
    /** @return list<string> every table cacti.sql declares, in file order, read as get_cacti_base_tables() reads them */
    public function baseTables(): array;

    /** The target's own base tables, read from its DATABASE(). */
    public function tableStatuses(DatabaseTarget $target): TableCatalog;

    public function innodbEnabled(DatabaseTarget $target): bool;

    public function filePerTable(DatabaseTarget $target): bool;

    /** $table must be in tableStatuses(). */
    public function statement(DatabaseTarget $target, string $table, TableChange $change): string;

    /** False when $table is not in tableStatuses(), which sends nothing, or when the server refused the statement. */
    public function convert(DatabaseTarget $target, string $table, TableChange $change): bool;

    public function recordFailure(DatabaseTarget $target, string $message): void;
}
