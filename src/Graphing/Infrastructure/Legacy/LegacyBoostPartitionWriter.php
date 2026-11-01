<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Graphing\Infrastructure\Legacy;

use Kadupul\Graphing\Application\Port\BoostPartitionResult;
use Kadupul\Graphing\Application\Port\BoostPartitionWriter;
use Kadupul\Platform\Contract\LegacyWorkerRunner;
use Symfony\Component\Process\Exception\ProcessTimedOutException;

/** bin/legacy-boost-partition.php, which runs the poller_boost.php child for one partition. */
/** Runs one partition flush in the legacy worker. A run the master no longer waits on is reported as stale and counts as ok. */
final readonly class LegacyBoostPartitionWriter implements BoostPartitionWriter
{
    public function __construct(private LegacyWorkerRunner $worker) {}

    #[\Override]
    public function flush(string $runId, int $partition, float $timeout): BoostPartitionResult
    {
        try {
            $run = $this->worker->run('legacy-boost-partition.php', 'BOOST_PARTITION', ['run' => $runId, 'partition' => $partition], $timeout);
        } catch (ProcessTimedOutException) {
            return new BoostPartitionResult(false, 0, 'timeout');
        }
        $status = $run['result']['status'] ?? null;
        if ($status === 'stale') {
            return new BoostPartitionResult(true, 0, 'stale');
        }
        $rows = $run['result']['rows'] ?? null;
        if ($status === 'ok' && $run['ok'] && is_int($rows) && $rows >= 0) {
            return new BoostPartitionResult(true, $rows, '');
        }

        return new BoostPartitionResult(false, 0, 'failed');
    }
}
