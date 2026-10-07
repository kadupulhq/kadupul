<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Domain\Schema;

final readonly class AddColumn implements AlterClause
{
    /** @param ?string $after the column it follows, or null for FIRST */
    public function __construct(public ColumnSpec $spec, public ?string $after, private string $legacy) {}

    #[\Override]
    public function legacy(): string
    {
        return $this->legacy;
    }
}
