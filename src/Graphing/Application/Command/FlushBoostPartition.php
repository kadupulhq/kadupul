<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Graphing\Application\Command;

/** One Boost partition of one master run, queued for a consumer. */
final readonly class FlushBoostPartition
{
    public const string RUN_PATTERN = '/^[0-9a-f]{32}$/D';

    public function __construct(public string $runId, public int $partition)
    {
        if (preg_match(self::RUN_PATTERN, $runId) !== 1) {
            throw new \InvalidArgumentException('A Boost run id is 32 lowercase hexadecimal characters.');
        }
        if ($partition < 1) {
            throw new \InvalidArgumentException('A Boost partition starts at 1.');
        }
    }
}
