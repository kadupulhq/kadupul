<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Navigation\Application\Port;

use Kadupul\IdentityAccess\Contract\Actor;

/** Decides whether the signed-in user may manage external links. */
interface LinkAccess
{
    /**
     * Returns the signed-in user when they may manage links.
     *
     * @throws \Kadupul\Navigation\Application\Query\LinkAccessDenied when there is no user or the user lacks access
     */
    public function authorize(): Actor;
    /**
     * Rechecks access inside a write transaction so a revoked grant cannot be used by a stale session.
     *
     * @throws \Kadupul\Navigation\Application\Query\LinkAccessDenied when the user is not the same permitted actor any more
     * @throws \LogicException when no transaction is active
     */
    public function assertCurrent(int $actorId): void;
}
