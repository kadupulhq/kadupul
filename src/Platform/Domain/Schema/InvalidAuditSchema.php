<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Domain\Schema;

final class InvalidAuditSchema extends \UnexpectedValueException
{
    public function __construct(public readonly int $lineNumber)
    {
        parent::__construct('docs/audit_schema.sql does not parse.');
    }
}
