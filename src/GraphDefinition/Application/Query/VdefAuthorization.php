<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\GraphDefinition\Application\Query;

use Kadupul\IdentityAccess\Contract\ConsoleAccess;
use Kadupul\GraphDefinition\Application\Port\VdefRealmAccess;

final readonly class VdefAuthorization
{
    public function __construct(private ConsoleAccess $access, private VdefRealmAccess $realm) {}

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
