<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\IdentityAccess\Application\Query;

use Kadupul\IdentityAccess\Application\Port\AuthenticatedSession;
use Kadupul\IdentityAccess\Contract\Actor;
use Kadupul\IdentityAccess\Contract\CurrentActor as CurrentActorContract;

final readonly class CurrentActor implements CurrentActorContract
{
    public function __construct(private AuthenticatedSession $session) {}

    public function __invoke(): ?Actor
    {
        return $this->session->consoleActor();
    }
}
