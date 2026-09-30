<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace Kadupul\GraphDefinition\Application\Port;

interface CdefRealmAccess
{
    public function canManageDefinitions(int $actorId): bool;
}
