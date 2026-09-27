<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Domain\Schema;

/** What one table needs. The caller quotes the table name. */
final readonly class TableChange
{
    public function __construct(public bool $dynamic, public ?TableCharset $charset, public bool $innodb) {}

    /** @return list<string> */
    public function clauses(): array
    {
        $clauses = [];
        if ($this->dynamic) {
            $clauses[] = 'ROW_FORMAT=Dynamic';
        }
        if ($this->charset !== null) {
            $clauses[] = $this->charset->clause();
        }
        if ($this->innodb) {
            $clauses[] = 'ENGINE=InnoDB';
        }

        return $clauses;
    }

    /**
     * The line convert_tables.php wrote to cacti.log. Operators grep for it, so
     * it keeps the original's spacing, its "Innodb", and its omission of
     * ROW_FORMAT. It is log text only; it never reaches the server.
     */
    public function legacyFailure(string $table): string
    {
        $sql = $this->charset === null ? '' : ' ' . $this->charset->clause();
        if ($this->innodb) {
            $sql .= ($sql === '' ? '' : ',') . ' ENGINE=Innodb';
        }

        return "FATAL: Conversion of Table '" . $table . "' Failed.  Command: 'ALTER TABLE `" . $table . '` ' . $sql . "'";
    }
}
