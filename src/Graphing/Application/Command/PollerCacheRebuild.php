<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Graphing\Application\Command;

use Kadupul\Graphing\Application\Port\PollerCacheOutcome;
use Kadupul\IdentityAccess\Contract\OperatorDatabase;

/** The result of one authorized rebuild_poller_cache.php run. */
final readonly class PollerCacheRebuild
{
    /** The worker failed or its outcome is unknown. */
    public const string FAILED = 'failed';

    /**
     * @param string $status a PollerCacheOutcome status, or FAILED
     * @param int $hosts the devices the master counted or a child processed
     */
    public function __construct(public OperatorDatabase $database, public string $status, public int $hosts = 0) {}

    /** Whether the script would have exited 0. */
    public function succeeded(): bool
    {
        return !in_array($this->status, [self::FAILED, PollerCacheOutcome::INTERRUPTED], true);
    }
}
