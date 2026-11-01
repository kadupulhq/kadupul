<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\IdentityAccess\Application\Command;

use Kadupul\IdentityAccess\Contract\OperatorDatabase;
use Kadupul\IdentityAccess\Domain\ListTarget;

/** The rows of one permission-target listing, with the target listed and how the listing ended. */
final readonly class PermissionListing
{
    /** @param list<list<string>> $rows the columns of the listing's heading, one list per row */
    public function __construct(
        public ListTarget $target,
        public OperatorDatabase $database,
        public ListOutcome $outcome,
        public array $rows = [],
    ) {}
}
