<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Application\Port;

/**
 * Per-user rows of the settings_user table, by user id and name.
 *
 * User ids arrive from the session and from earlier queries as int or as
 * numeric text, and are compared by the server as numbers. Names follow the
 * column's case- and accent-insensitive collation, as in ConfigurationSettings.
 */
interface UserSettings
{
    /** The stored value, or null when the user has no row for the name. */
    public function value(int|string $userId, string $name): ?string;

    public function exists(int|string $userId, string $name): bool;

    /** As ConfigurationSettings::write(), including the affected-row count. */
    public function write(int|string $userId, string $name, string|int $value): int;

    /** Deletes the row and returns how many went; a missing row is not an error. */
    public function remove(int|string $userId, string $name): int;
}
