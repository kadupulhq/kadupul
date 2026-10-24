<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Graphing\Application\Port;

use Kadupul\Graphing\Domain\RrdCommand;

interface RrdUpdater
{
    /**
     * Runs one rrdtool update against the target. RRDtool applies rows in
     * order and keeps those before a failing row, so a failure does not mean
     * nothing was written; the caller rereads the file to find out.
     *
     * @throws StorageRejected when RRDtool reports failure or does not finish in time
     */
    public function update(RrdTarget $target, RrdCommand $command): void;
}
