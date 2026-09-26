<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Application\ReadModel;

/** What happened to one table. */
final readonly class TableOutcome
{
    /**
     * @param bool $attempted whether an applied run tried to change the table:
     *     it sent a statement, or refused a name the catalog does not list.
     *     The command audits exactly these.
     */
    public function __construct(
        public string $name,
        public TableResult $result,
        public ?int $rows,
        public ?string $statement,
        public bool $attempted,
    ) {}

    /** @return array{name: string, result: TableResult, rows: ?int, statement: ?string} */
    public function line(): array
    {
        // Keys in this order: the JSON output and the tests rely on it.
        return ['name' => $this->name, 'result' => $this->result, 'rows' => $this->rows, 'statement' => $this->statement];
    }
}
