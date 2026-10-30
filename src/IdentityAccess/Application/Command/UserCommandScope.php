<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\IdentityAccess\Application\Command;

use Kadupul\IdentityAccess\Contract\Actor;
use Kadupul\IdentityAccess\Contract\OperatorDatabase;

/** The database a user command works on and the operator allowed to act there. */
final readonly class UserCommandScope
{
    public function __construct(public OperatorDatabase $database, public Actor $actor) {}
}
