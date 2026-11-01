<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Graphing\Application\Command;

use Kadupul\Graphing\Application\Port\PollerOutputDrainResult;
use Kadupul\IdentityAccess\Contract\OperatorDatabase;

/** What cli/poller_output_empty.php reports for one run: the database it worked on and how the pass ended. */
final readonly class PollerOutputEmptying
{
    /** @param string $status one of PollerOutputDrainResult::STATUSES */
    public function __construct(public OperatorDatabase $database, public string $status, public int $updates = 0) {}

    public function succeeded(): bool
    {
        return $this->status === PollerOutputDrainResult::OK;
    }
}
