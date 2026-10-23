<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Graphing\Infrastructure\Rrd;

use Kadupul\Graphing\Application\Port\RrdFileInfo;
use Kadupul\Graphing\Application\Port\RrdTarget;
use Kadupul\Graphing\Application\Port\RrdTargetInspector;
use Kadupul\Graphing\Application\Port\StorageRejected;
use Kadupul\Telemetry\Contract\RejectionReason;

/** Reads data-source types and the last update from `rrdtool info`, which leaves the file unchanged. */
final class RrdtoolInspector implements RrdTargetInspector
{
    public function __construct(private readonly RrdtoolProcess $rrdtool) {}

    public function inspect(RrdTarget $target): RrdFileInfo
    {
        clearstatcache(true, $target->path);
        if (!is_file($target->path)) {
            throw new StorageRejected(RejectionReason::TargetMissing, 'The RRD file does not exist.');
        }
        $result = $this->rrdtool->run(['info', $target->path]);
        if ($result->exitCode !== 0) {
            throw new StorageRejected(RejectionReason::StorageFailure, sprintf('rrdtool info exited with status %d: %s', $result->exitCode, $result->diagnostic()));
        }
        if (preg_match('/^last_update = ([0-9]{1,19})$/m', $result->stdout, $last) !== 1) {
            throw new StorageRejected(RejectionReason::StorageFailure, 'rrdtool info did not report when the file was last written.');
        }
        preg_match_all('/^ds\[([A-Za-z0-9_-]{1,19})\]\.type = "([A-Z]+)"$/m', $result->stdout, $types, PREG_SET_ORDER);

        return new RrdFileInfo((int) $last[1], array_column($types, 2, 1));
    }
}
