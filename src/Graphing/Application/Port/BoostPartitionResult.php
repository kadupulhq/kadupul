<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Graphing\Application\Port;

/** $detail is 'stale' when the master no longer waits on the run, and names the cause on failure. */
final readonly class BoostPartitionResult
{
    public function __construct(public bool $ok, public int $rows, public string $detail) {}
}
