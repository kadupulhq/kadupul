<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Domain\Schema;

/**
 * repair_database()'s one ALTER TABLE for a table: its clauses, then the
 * InnoDB, Dynamic row format and character set options it always appended.
 * The caller quotes the table name.
 */
final readonly class TableAlter
{
    public bool $innodb;
    public ?DefaultCharset $charset;
    private string $legacyCharset;

    /** @param non-empty-list<AlterClause> $clauses */
    public function __construct(public string $table, public array $clauses, TableStatus $status)
    {
        $this->innodb = $status->engine === 'MyISAM';
        $this->legacyCharset = DefaultCharset::legacyName($status->collation);
        $this->charset = DefaultCharset::tryFrom($this->legacyCharset);
    }

    /** False when a clause or the character set has no typed form; such a table is reported, not sent. */
    public function buildable(): bool
    {
        return $this->charset !== null && !array_any($this->clauses, static fn(AlterClause $clause): bool => $clause instanceof UnbuildableClause);
    }

    /** The statement as the original printed it for --alters and after a failure; it never reaches the server. */
    public function legacy(): string
    {
        $suffix = ($this->innodb ? ",\n   ENGINE=InnoDB ROW_FORMAT=Dynamic CHARSET=" : ",\n   ROW_FORMAT=Dynamic CHARSET=") . $this->legacyCharset;

        return 'ALTER TABLE `' . $this->table . "`\n   " . implode(",\n   ", array_map(static fn(AlterClause $clause): string => $clause->legacy(), $this->clauses)) . $suffix . ';';
    }
}
