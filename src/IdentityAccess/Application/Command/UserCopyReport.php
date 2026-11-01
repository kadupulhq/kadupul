<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\IdentityAccess\Application\Command;

use Kadupul\IdentityAccess\Contract\OperatorDatabase;

/** The outcome of a user copy, the database it ran against, and whether it was a dry run. */
final readonly class UserCopyReport
{
    public function __construct(public UserCopyOutcome $outcome, public OperatorDatabase $database, public bool $dryRun) {}

    /** Whether the copy was made, or for a dry run would be attempted. */
    public function succeeded(): bool
    {
        return $this->outcome === UserCopyOutcome::Copied || $this->outcome === UserCopyOutcome::Planned;
    }
}
