<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\AggregateTemplate\Application\Port;

use Kadupul\IdentityAccess\Contract\Actor;

interface AggregateTemplatePermissions
{
    public function canManage(Actor $actor): bool;
}
