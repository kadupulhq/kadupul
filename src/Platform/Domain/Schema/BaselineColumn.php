<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Domain\Schema;

/** One table_columns row: a column as a pristine install's SHOW COLUMNS listed it. */
final readonly class BaselineColumn
{
    public function __construct(
        public string $table,
        public int $sequence,
        public string $field,
        public ?string $type,
        public ?string $null,
        public ?string $key,
        public ?string $default,
        public ?string $extra,
    ) {}

    /** @return array<string, int|string|null> keyed as table_columns names its columns, which ColumnDrift rewrites in place */
    public function row(): array
    {
        return ['table_name' => $this->table, 'table_sequence' => $this->sequence, 'table_field' => $this->field, 'table_type' => $this->type,
            'table_null' => $this->null, 'table_key' => $this->key, 'table_default' => $this->default, 'table_extra' => $this->extra];
    }
}
