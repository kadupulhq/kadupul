<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Domain\Schema;

/**
 * A column definition in typed parts: what make_column_alter() and
 * make_column_add() wrote as text. The adapter quotes the name and the
 * default; everything else comes from ColumnType and ColumnExtra.
 */
final readonly class ColumnSpec
{
    /**
     * @param ?string $default a literal default, or null for none
     * @param bool $defaultNow DEFAULT CURRENT_TIMESTAMP instead of a literal
     */
    public function __construct(
        public string $name,
        public ColumnType $type,
        public bool $notNull,
        public ?string $default,
        public bool $defaultNow,
        public ColumnExtra $extra,
    ) {}
}
