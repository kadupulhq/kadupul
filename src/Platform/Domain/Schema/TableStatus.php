<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Domain\Schema;

/** One information_schema.TABLES row: what convert_tables.php looked at. */
final readonly class TableStatus
{
    public function __construct(public ?string $engine, public ?string $collation, public ?string $rowFormat, public ?int $rows) {}
}
