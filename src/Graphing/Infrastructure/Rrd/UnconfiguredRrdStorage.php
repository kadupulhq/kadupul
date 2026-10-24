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
use Kadupul\Graphing\Application\Port\RrdTargetLocator;
use Kadupul\Graphing\Application\Port\RrdUpdater;
use Kadupul\Graphing\Application\Port\StorageRejected;
use Kadupul\Graphing\Domain\RrdCommand;
use Kadupul\Monitoring\Contract\StorageReference;
use Kadupul\Telemetry\Contract\RejectionReason;

/**
 * Stands in while the RRD root or the rrdtool binary is empty. The sink still
 * has to be constructible so its closed write gate can answer, but nothing may
 * guess a location or a binary, so every storage call is refused.
 */
final class UnconfiguredRrdStorage implements RrdTargetLocator, RrdTargetInspector, RrdUpdater
{
    private const DETAIL = 'RRD storage is not configured: set the RRD root and the rrdtool binary before enabling model-driven writes.';

    public function locate(StorageReference $reference): RrdTarget
    {
        throw $this->refusal();
    }

    public function inspect(RrdTarget $target): RrdFileInfo
    {
        throw $this->refusal();
    }

    public function update(RrdTarget $target, RrdCommand $command): void
    {
        throw $this->refusal();
    }

    private function refusal(): StorageRejected
    {
        return new StorageRejected(RejectionReason::StorageFailure, self::DETAIL);
    }
}
