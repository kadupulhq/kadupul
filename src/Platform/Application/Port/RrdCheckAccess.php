<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Application\Port;

use Kadupul\IdentityAccess\Contract\Actor;

interface RrdCheckAccess
{
    public function authorize(): Actor;

    /** Recheck the account and realms inside the caller's open write transaction. */
    public function assertCurrent(int $actorId): void;
}
