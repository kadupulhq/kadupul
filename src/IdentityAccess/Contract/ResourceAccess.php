<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\IdentityAccess\Contract;

interface ResourceAccess
{
    public function canManageTree(int $actorId, int $ownerId): bool;
    public function canManageReport(int $actorId, int $ownerId): bool;
}
