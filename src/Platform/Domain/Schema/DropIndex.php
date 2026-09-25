<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Domain\Schema;

/** An index the server has and the audit schema does not. */
final readonly class DropIndex implements AlterClause
{
    public function __construct(public string $name) {}

    /** The original left the name unquoted here, unlike every other clause. */
    #[\Override]
    public function legacy(): string
    {
        return 'DROP INDEX ' . $this->name;
    }
}
