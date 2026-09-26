<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Application\Port;

use Kadupul\Platform\Domain\Schema\ColumnDefinition;

interface ColumnWidening
{
    /** The target's own base tables and columns, read from its DATABASE(). */
    public function catalog(DatabaseTarget $target): ColumnCatalog;

    /**
     * $table and each column must be in catalog().
     *
     * @param non-empty-list<ColumnDefinition> $columns as catalog() read them
     */
    public function statement(DatabaseTarget $target, string $table, array $columns): string;

    /**
     * @param non-empty-list<ColumnDefinition> $columns as catalog() read them
     * @return bool false when $table or a column is no longer in the catalog
     *     exactly as given, which sends nothing, or when the server refused the statement
     */
    public function widen(DatabaseTarget $target, string $table, array $columns): bool;
}
