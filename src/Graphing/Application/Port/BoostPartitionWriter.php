<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Graphing\Application\Port;

interface BoostPartitionWriter
{
    /** @param float $timeout seconds the whole flush may take */
    public function flush(string $runId, int $partition, float $timeout): BoostPartitionResult;
}
