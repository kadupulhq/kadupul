<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Graphing\Application\Command;

use Kadupul\Graphing\Application\Port\RejectedSampleReplayer;
use Kadupul\Graphing\Application\Port\RejectedSampleReplayResult;

/**
 * Use case behind cli/replay_rejected_samples.php: returns the samples that
 * RRDtool kept refusing from poller_output_rejected to poller_output for the
 * next drain, for one data source or for all, or counts them on a dry run.
 */
final readonly class ReplayRejectedSamples
{
    private const string ACTION = 'rejected-samples.replay';

    public function __construct(private GraphCommandAccess $access, private RejectedSampleReplayer $replayer) {}

    /**
     * The original never left this installation's database, so the operator is
     * read from its own accounts and there is no main-database switch.
     *
     * @param ?string $operator account to act as; null means the admin_user setting
     * @param ?int $localDataId a positive data source id, or null for every data source
     * @param ?\Closure(string, string): void $relay receives the 'out' or 'err' text the run printed, as it is printed
     * @throws GraphManagementDenied before anything is read or moved, when the operator lacks the Console Access and Devices realms
     */
    public function __invoke(?string $operator, ?int $localDataId, bool $dryRun, ?\Closure $relay = null): RejectedSampleReplay
    {
        // No web page replays these; data_sources.php, realm 3, owns the data they belong to. A dry run moves nothing but passes the same check.
        $scope = $this->access->select(self::ACTION, true, $operator, !$dryRun, ManagedArea::Devices);
        $correlation = $this->access->correlation();
        $result = null;
        try {
            $result = $this->replayer->replay($localDataId, $dryRun, $relay);
        } catch (\RuntimeException) {
            // Reported as a failure; the audit record below says the same.
        } finally {
            // A dry run changes nothing, so it records nothing.
            if (!$dryRun) {
                $this->access->change($correlation, $scope, self::ACTION, 'rejected-samples', $localDataId === null ? 'all' : (string) $localDataId, $result?->succeeded() === true);
            }
        }

        return $result === null
            ? new RejectedSampleReplay($scope->database, $localDataId, $dryRun, RejectedSampleReplayResult::FAILED)
            : new RejectedSampleReplay($scope->database, $localDataId, $dryRun, $result->status, $result->samples, $result->reason);
    }
}
