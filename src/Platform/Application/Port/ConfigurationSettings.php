<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Application\Port;

/**
 * The installation-wide rows of the settings table, by name.
 *
 * Names are matched by the column's collation, which ignores case and
 * accents, so 'POLLER_INTERVAL' reads and overwrites the 'poller_interval'
 * row. Failures surface as exceptions; callers that need the legacy false
 * return values translate them.
 */
interface ConfigurationSettings
{
    /** The stored value, or null when the name has no row. */
    public function value(string $name): ?string;

    public function exists(string $name): bool;

    /**
     * @param list<string> $names
     *
     * @return array<string, string> value by stored name, for the names that have a row
     */
    public function values(array $names): array;

    /**
     * Inserts the row or replaces its value. An int is stored as its decimal
     * text. Returns the server's affected-row count: 1 inserted, 2 replaced;
     * for a row that already held the value, 0, or 1 on a session that
     * counts found rows, as the legacy one does.
     */
    public function write(string $name, string|int $value): int;
}
