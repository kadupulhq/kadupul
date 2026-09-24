<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\IdentityAccess\Contract;

interface ConsoleAccess
{
    public function consoleActor(): ?Actor;
    public function canManageDevices(Actor $actor): bool;
}
