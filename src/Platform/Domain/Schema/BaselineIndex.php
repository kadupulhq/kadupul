<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Domain\Schema;

/** One table_indexes row: one column of an index as a pristine install's SHOW INDEXES listed it. */
final readonly class BaselineIndex
{
    public function __construct(
        public string $table,
        public ?int $nonUnique,
        public string $keyName,
        public int $seqInIndex,
        public string $columnName,
        public ?string $collation,
        public ?int $cardinality,
        public ?string $subPart,
        public ?string $packed,
        public ?string $null,
        public ?string $indexType,
        public ?string $comment,
    ) {}

    /** @return array<string, int|string|null> keyed as table_indexes names its columns */
    public function row(): array
    {
        return ['idx_table_name' => $this->table, 'idx_non_unique' => $this->nonUnique, 'idx_key_name' => $this->keyName,
            'idx_seq_in_index' => $this->seqInIndex, 'idx_column_name' => $this->columnName, 'idx_collation' => $this->collation,
            'idx_cardinality' => $this->cardinality, 'idx_sub_part' => $this->subPart, 'idx_packed' => $this->packed,
            'idx_null' => $this->null, 'idx_index_type' => $this->indexType, 'idx_comment' => $this->comment];
    }
}
