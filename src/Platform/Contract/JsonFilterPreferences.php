<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Contract;

use Closure;

/** Persist allowlisted string filters under feature-owned validation and access policies. */
interface JsonFilterPreferences
{
    /** The caller authorizes before this protected read.
     * @param list<string> $keys
     * @param Closure(array<string, string>): mixed $validateStored Reject stale stored domain values only.
     * @return array<string, string>|null
     */
    public function load(int $actorId, string $name, array $keys, Closure $validateStored): ?array;

    /**
     * @param array<array-key, mixed> $filters
     * @param list<string> $keys
     * @param Closure(): int $authorize Recheck the feature policy inside the write transaction.
     */
    public function save(string $name, array $filters, array $keys, string $invalidShape, Closure $authorize): void;
}
