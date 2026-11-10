<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Infrastructure\Legacy;

/** Stop legacy reconnect/retry while a caller owns database locks or a transaction. */
final class LegacyDatabaseFailureScope
{
    private static int $depth = 0;

    private function __construct() {}

    public static function active(): bool
    {
        return self::$depth > 0;
    }

    public static function run(callable $operation): mixed
    {
        self::$depth++;
        try {
            return $operation();
        } finally {
            self::$depth--;
        }
    }
}
