<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\GraphDefinition\Application\Query;

use Kadupul\IdentityAccess\Contract\ConsoleAccess;
use Kadupul\GraphDefinition\Application\Port\VdefRealmAccess;

/** Checks that the signed-in console user may manage VDEFs. */
final readonly class VdefAuthorization
{
    /** Wires the console user lookup and the realm check. */
    public function __construct(private ConsoleAccess $access, private VdefRealmAccess $realm) {}

    /**
     * Returns the signed-in user's id when they may manage VDEFs.
     *
     * @throws VdefAccessDenied when nobody is signed in (unauthenticated) or the user lacks the realm
     */
    public function actor(): int
    {
        $actor = $this->access->consoleActor();
        if ($actor === null) {
            throw new VdefAccessDenied(true);
        }
        if (!$this->realm->canManageDefinitions($actor->id)) {
            throw new VdefAccessDenied();
        }

        return $actor->id;
    }
}
