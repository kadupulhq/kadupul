<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Graphing\Application\Command;

use Kadupul\Graphing\Application\Port\RejectedSampleReplayResult;
use Kadupul\IdentityAccess\Contract\OperatorDatabase;

/** What cli/replay_rejected_samples.php reports for one run: the database it worked on, its scope and how it ended. */
final readonly class RejectedSampleReplay
{
    /**
     * @param ?int $localDataId the data source replayed, or null for every data source
     * @param string $status one of RejectedSampleReplayResult::STATUSES
     */
    public function __construct(
        public OperatorDatabase $database,
        public ?int $localDataId,
        public bool $dryRun,
        public string $status,
        public int $samples = 0,
        public string $reason = '',
    ) {}

    public function succeeded(): bool
    {
        return $this->status === RejectedSampleReplayResult::OK;
    }
}
