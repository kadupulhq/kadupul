<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Graphing\Application\Port;

use Kadupul\Graphing\Domain\RrdCommand;

/**
 * One rrdtool update for one file, without the file argument, which only
 * the updater knows. Positions are kept per row so a partly applied call
 * can be reported row by row.
 */
final readonly class PlannedUpdate
{
    /** @param array<int, list<int>> $positionsByTimestamp sample positions keyed by the epoch second of their row, ascending */
    public function __construct(
        public RrdCommand $command,
        public array $positionsByTimestamp,
    ) {
        if ($command->verb !== 'update') {
            throw new \InvalidArgumentException('A planned update runs rrdtool update.');
        }
    }
}
