<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Graphing\Infrastructure\Rrd;

use Kadupul\Graphing\Application\Port\RrdTarget;
use Kadupul\Graphing\Application\Port\RrdUpdater;
use Kadupul\Graphing\Application\Port\StorageRejected;
use Kadupul\Graphing\Domain\RrdCommand;
use Kadupul\Telemetry\Contract\RejectionReason;

/** Runs `rrdtool update <file> --template <names> <rows>...` as separate arguments. */
final class RrdtoolUpdater implements RrdUpdater
{
    public function __construct(private readonly RrdtoolProcess $rrdtool) {}

    public function update(RrdTarget $target, RrdCommand $command): void
    {
        $arguments = $command->arguments;
        if ($command->verb !== 'update' || count($arguments) < 3 || $arguments[0] !== '--template') {
            throw new \InvalidArgumentException('Only a templated rrdtool update with at least one row is run.');
        }
        foreach (array_slice($arguments, 1) as $argument) {
            // rrdtool would parse a hyphenated argument as an option, such as --daemon, which redirects the write.
            if (str_starts_with($argument, '-')) {
                throw new \InvalidArgumentException('A template or row must not look like an rrdtool option.');
            }
        }
        $result = $this->rrdtool->run(['update', $target->path, ...$arguments]);
        if ($result->exitCode === 0) {
            return;
        }
        if (preg_match('/illegal attempt to update using time ([0-9]+) when last update time is ([0-9]+)/', $result->stderr, $m) === 1) {
            throw new StorageRejected(RejectionReason::OutOfOrder, sprintf('RRDtool refused second %s because the file already holds second %s.', $m[1], $m[2]));
        }
        throw new StorageRejected(RejectionReason::StorageFailure, sprintf('rrdtool update exited with status %d: %s', $result->exitCode, $result->diagnostic()));
    }
}
