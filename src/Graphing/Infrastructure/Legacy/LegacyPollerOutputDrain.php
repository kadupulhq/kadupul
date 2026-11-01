<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Graphing\Infrastructure\Legacy;

use Kadupul\Graphing\Application\Port\PollerOutputDrain;
use Kadupul\Graphing\Application\Port\PollerOutputDrainResult;
use Kadupul\Platform\Contract\LegacyWorkerRunner;
use Symfony\Component\Process\Process;

/** Runs bin/legacy-poller-output-empty.php, which holds what cli/poller_output_empty.php did with lib/poller.php and lib/rrd.php. */
final readonly class LegacyPollerOutputDrain implements PollerOutputDrain
{
    public const string MARKER = 'KADUPUL_POLLER_OUTPUT_EMPTY_RESULT';

    public function __construct(private LegacyWorkerRunner $worker) {}

    #[\Override]
    public function drain(?\Closure $relay = null): PollerOutputDrainResult
    {
        // No timeout: the original ran until the queue was empty, and stopping it between two RRD updates helps nobody.
        $run = $this->worker->run(
            'legacy-poller-output-empty.php',
            self::MARKER,
            [],
            null,
            $relay === null ? null : static fn(string $type, string $text) => $relay($type === Process::ERR ? 'err' : 'out', $text)
        );
        $status = $run['result']['status'] ?? null;
        $updates = $run['result']['updates'] ?? null;
        if (!is_string($status) || !in_array($status, PollerOutputDrainResult::STATUSES, true) || !is_int($updates) || $updates < 0) {
            throw new \RuntimeException('The poller output outcome is unknown.');
        }
        // The worker exits 0 with status "ok" only after the queue was empty; anything else must not read as success.
        if (($status === PollerOutputDrainResult::OK) !== $run['ok']) {
            return new PollerOutputDrainResult(PollerOutputDrainResult::FAILED);
        }

        return new PollerOutputDrainResult($status, $status === PollerOutputDrainResult::OK ? $updates : 0);
    }
}
