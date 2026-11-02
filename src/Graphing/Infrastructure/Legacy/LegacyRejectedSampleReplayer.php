<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Graphing\Infrastructure\Legacy;

use Kadupul\Graphing\Application\Port\RejectedSampleReplayer;
use Kadupul\Graphing\Application\Port\RejectedSampleReplayResult;
use Kadupul\Platform\Contract\LegacyWorkerRunner;
use Symfony\Component\Process\Process;

/**
 * Runs bin/legacy-rejected-samples-replay.php, which holds what
 * cli/replay_rejected_samples.php did with lib/poller.php and
 * lib/rrd_maintenance.php, so poller_replay_rejected() stays the one
 * implementation of the move.
 */
final readonly class LegacyRejectedSampleReplayer implements RejectedSampleReplayer
{
    public const string MARKER = 'KADUPUL_REJECTED_SAMPLES_REPLAY_RESULT';

    public function __construct(private LegacyWorkerRunner $worker) {}

    #[\Override]
    public function replay(?int $localDataId, bool $dryRun, ?\Closure $relay = null): RejectedSampleReplayResult
    {
        // No timeout: poller_replay_rejected() moves the samples in one transaction, and the original ran it to the end.
        $run = $this->worker->run('legacy-rejected-samples-replay.php', self::MARKER, [
            'local_data_id' => $localDataId,
            'dry_run' => $dryRun,
        ], null, $relay === null ? null : static fn(string $type, string $text) => $relay($type === Process::ERR ? 'err' : 'out', $text));
        $status = $run['result']['status'] ?? null;
        $samples = $run['result']['samples'] ?? null;
        $reason = $run['result']['reason'] ?? null;
        if (!is_string($status) || !in_array($status, RejectedSampleReplayResult::STATUSES, true) || !is_int($samples) || $samples < 0 || !is_string($reason)) {
            throw new \RuntimeException('The rejected sample replay outcome is unknown.');
        }
        // The worker exits 0 with status "ok" only after the move committed; anything else must not read as success.
        if (($status === RejectedSampleReplayResult::OK) !== $run['ok']) {
            return new RejectedSampleReplayResult(RejectedSampleReplayResult::FAILED);
        }

        return $status === RejectedSampleReplayResult::OK
            ? new RejectedSampleReplayResult($status, $samples)
            : new RejectedSampleReplayResult($status, 0, $status === RejectedSampleReplayResult::UNSAFE_QUEUE ? $reason : '');
    }
}
