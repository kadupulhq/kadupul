<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Domain\Schema;

/** make_index_alter(): drop what the server has, then add the index as the audit schema lists it. */
final readonly class RebuildIndex implements AlterClause
{
    /**
     * @param list<?string> $drops in order; null is DROP PRIMARY KEY, a name is DROP INDEX
     * @param non-empty-list<string> $columns in idx_seq_in_index order
     */
    public function __construct(
        public array $drops,
        public bool $primary,
        public bool $unique,
        public string $name,
        public array $columns,
        public IndexAlgorithm $using,
        private string $legacy,
    ) {}

    #[\Override]
    public function legacy(): string
    {
        return $this->legacy;
    }
}
