<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\IdentityAccess\Application\Command;

use Kadupul\IdentityAccess\Contract\OperatorDatabase;

final readonly class GrantReport
{
    public function __construct(public GrantOutcome $outcome, public OperatorDatabase $database, public bool $dryRun) {}

    /** Whether the grant was made, or would be; a validation-only run with no user counts too. */
    public function succeeded(): bool
    {
        return in_array($this->outcome, [GrantOutcome::Granted, GrantOutcome::Planned, GrantOutcome::NoUser], true);
    }
}
