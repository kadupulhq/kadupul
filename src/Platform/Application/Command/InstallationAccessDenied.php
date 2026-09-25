<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Application\Command;

use Kadupul\Platform\Application\Port\DatabaseTarget;

final class InstallationAccessDenied extends \RuntimeException
{
    /** Who was refused, when known, and on which database; write commands audit both. */
    public function __construct(public readonly ?int $actorId = null, public readonly ?DatabaseTarget $target = null)
    {
        parent::__construct('Installation access denied.');
    }
}
