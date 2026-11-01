<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Graphing\Application\Port;

/** Moves samples from poller_output_rejected back to poller_output through the legacy poller code that owns the move. */
interface RejectedSampleReplayer
{
    /**
     * Refuses on an online remote collector, then (unless $dryRun) on a queue
     * that is not durable, then calls poller_replay_rejected(), as
     * replay_rejected_samples.php did, on the installation's own database.
     * The move is one transaction in that function; a refusal or failure
     * moves nothing.
     *
     * @param ?int $localDataId one data source, or null for every data source
     * @param bool $dryRun count without moving
     * @param ?\Closure(string, string): void $relay called with 'out' or 'err' and the text the original printed to stdout or stderr
     *     while it ran, in order; null drops that text
     * @return RejectedSampleReplayResult how the run ended; a refusal is a status, not an exception
     * @phpstan-impure
     * @throws \RuntimeException when the worker could not report an outcome at all
     */
    public function replay(?int $localDataId, bool $dryRun, ?\Closure $relay = null): RejectedSampleReplayResult;
}
