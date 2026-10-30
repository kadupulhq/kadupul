<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Graphing\Application\Command;

use Kadupul\IdentityAccess\Contract\OperatorDatabase;

final class GraphManagementDenied extends \RuntimeException
{
    /** Who was refused, when known, and on which database; the refusal is audited with both. */
    public function __construct(public readonly ?int $actorId = null, public readonly ?OperatorDatabase $database = null)
    {
        parent::__construct('Graph management access denied.');
    }
}
