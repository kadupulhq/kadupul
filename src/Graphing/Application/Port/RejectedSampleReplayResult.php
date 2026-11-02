<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Graphing\Application\Port;

/**
 * How one run of cli/replay_rejected_samples.php ended. The statuses are the
 * script's endings, plus FAILED for a worker that could not report one, which
 * the script could only have shown as a PHP fatal error.
 */
final readonly class RejectedSampleReplayResult
{
    public const string OK = 'ok';
    /** An online remote collector queues into the main database, where the rejected samples are. */
    public const string REMOTE_COLLECTOR = 'remote_collector';
    /** poller_output is not a durable (InnoDB) table, so a replay could lose samples on a restart. */
    public const string UNSAFE_QUEUE = 'unsafe_queue';
    /** poller_replay_rejected() reported that nothing was moved. */
    public const string FAILED = 'failed';

    /** @var list<string> */
    public const array STATUSES = [self::OK, self::REMOTE_COLLECTOR, self::UNSAFE_QUEUE, self::FAILED];

    /**
     * @param string $status one of self::STATUSES
     * @param int $samples rejected samples replayed, or that would be replayed on a dry run; only meaningful when the status is OK
     * @param string $reason the queue configuration error, for UNSAFE_QUEUE
     */
    public function __construct(public string $status, public int $samples = 0, public string $reason = '') {}

    public function succeeded(): bool
    {
        return $this->status === self::OK;
    }
}
