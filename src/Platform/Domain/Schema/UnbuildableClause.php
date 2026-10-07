<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Domain\Schema;

/**
 * A clause the original wrote that has no typed form: a type outside
 * ColumnType, an EXTRA such as the audit schema's "1", an index with no
 * USING. Its table's ALTER is reported and not sent. The server refused
 * every one of these when the original sent it.
 */
final readonly class UnbuildableClause implements AlterClause
{
    public function __construct(private string $legacy) {}

    #[\Override]
    public function legacy(): string
    {
        return $this->legacy;
    }
}
