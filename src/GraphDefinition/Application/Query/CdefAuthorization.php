<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\GraphDefinition\Application\Query;

use Kadupul\GraphDefinition\Application\Port\CdefRealmAccess;
use Kadupul\IdentityAccess\Contract\ConsoleAccess;

final readonly class CdefAuthorization
{
    public function __construct(private ConsoleAccess $access, private CdefRealmAccess $realm) {}

    public function actor(): int
    {
        $actor = $this->access->consoleActor();
        if ($actor === null) {
            throw new CdefAccessDenied(true);
        }
        if (!$this->realm->canManageDefinitions($actor->id)) {
            throw new CdefAccessDenied();
        }

        return $actor->id;
    }
}
