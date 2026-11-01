<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Graphing\Application\Port;

/**
 * How one pass of cli/poller_output_empty.php ended: the statuses are the
 * script's four exits, plus FAILED for a worker that died, which the script
 * could only have shown as a PHP fatal error.
 */
final readonly class PollerOutputDrainResult
{
    public const string OK = 'ok';
    public const string RRD_UNAVAILABLE = 'rrd_unavailable';
    public const string COUNT_UNAVAILABLE = 'count_unavailable';
    public const string NO_PROGRESS = 'no_progress';
    public const string FAILED = 'failed';

    /** @var list<string> */
    public const array STATUSES = [self::OK, self::RRD_UNAVAILABLE, self::COUNT_UNAVAILABLE, self::NO_PROGRESS, self::FAILED];

    /**
     * @param string $status one of self::STATUSES
     * @param int $updates RRD updates made; only meaningful when the status is OK
     */
    public function __construct(public string $status, public int $updates = 0) {}

    public function succeeded(): bool
    {
        return $this->status === self::OK;
    }
}
