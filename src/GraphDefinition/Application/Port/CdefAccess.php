<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\GraphDefinition\Application\Port;

use Kadupul\IdentityAccess\Contract\Actor;

/** Decides whether the signed-in user may manage CDEFs. */
interface CdefAccess
{
    /**
     * Returns the signed-in user when they may manage CDEFs.
     *
     * @throws \Kadupul\GraphDefinition\Application\Query\CdefAccessDenied when nobody is signed in or the user lacks the CDEF realm
     */
    public function authorize(): Actor;

    /** Recheck the account and realms inside the caller's open write transaction. */
    public function assertCurrent(int $actorId): void;
}
