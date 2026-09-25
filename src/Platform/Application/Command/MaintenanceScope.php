<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Application\Command;

use Kadupul\IdentityAccess\Contract\Actor;
use Kadupul\Platform\Application\Port\DatabaseTarget;

final readonly class MaintenanceScope
{
    public function __construct(public DatabaseTarget $target, public Actor $actor) {}
}
