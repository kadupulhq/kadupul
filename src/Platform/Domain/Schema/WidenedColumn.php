<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Domain\Schema;

/**
 * A column the server holds in a wider type than the audit schema lists, as
 * kadupul:database:widen-id-columns leaves it. The audit reports it and sends
 * no MODIFY, which would narrow it back and, under a lenient SQL mode,
 * truncate its values without an error.
 */
final readonly class WidenedColumn
{
    public function __construct(public string $field, public string $type, public string $baseline) {}

    public function line(): string
    {
        return "WARNING Col: '" . $this->field . "', widened locally.  Audit schema: '" . $this->baseline . "', Is: '" . $this->type . "'.  Not narrowed.";
    }
}
