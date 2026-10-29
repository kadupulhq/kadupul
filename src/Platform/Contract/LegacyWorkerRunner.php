<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Contract;

/** Starts one bin/legacy-*.php worker. Only a module's own worker adapter may name it. */
interface LegacyWorkerRunner
{
    /**
     * @param array<string, mixed> $command
     * @param ?float $timeout seconds, or null for none
     * @param ?\Closure(string, string): void $progress stdout lines except the protocol marker, and stderr chunks
     * @return array{output: string, errors: string, ok: bool, result: array<mixed>}
     */
    public function run(string $script, string $marker, array $command, ?float $timeout, ?\Closure $progress = null): array;
}
