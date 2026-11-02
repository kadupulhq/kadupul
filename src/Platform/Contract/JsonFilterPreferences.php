<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Contract;

use Closure;

/** Persist feature-owned filter shapes after the feature has validated them. */
interface JsonFilterPreferences
{
    /** The caller authorizes before this protected read.
     * @return array<array-key, mixed>|null
     */
    public function load(int $actorId, string $name): ?array;

    /**
     * @param array<string, string> $filters
     * @param Closure(): int $authorize Recheck the feature policy inside the write transaction.
     */
    public function save(string $name, array $filters, Closure $authorize): void;
}
