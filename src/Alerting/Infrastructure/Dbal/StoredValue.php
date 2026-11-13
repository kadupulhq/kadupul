<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Alerting\Infrastructure\Dbal;

/**
 * Reads column values as the driver returns them: native integers and floats,
 * or their decimal strings. Anything else is refused rather than cast, so a
 * value such as '1e3' or '' never becomes a count.
 */
final class StoredValue
{
    public static function int(mixed $value, string $row, string $column): int
    {
        if (is_int($value)) {
            return $value;
        }
        // Canonical decimal only, and within PHP's range: the round trip fails otherwise.
        if (is_string($value) && preg_match('/\A-?(0|[1-9][0-9]*)\z/', $value) === 1 && (string) (int) $value === $value) {
            return (int) $value;
        }
        throw StoredAlertingRowUnreadable::because($row, $column . ' is not an integer.');
    }

    public static function nullableInt(mixed $value, string $row, string $column): ?int
    {
        return $value === null ? null : self::int($value, $row, $column);
    }

    public static function flag(mixed $value, string $row, string $column): bool
    {
        return match (self::int($value, $row, $column)) {
            0 => false,
            1 => true,
            default => throw StoredAlertingRowUnreadable::because($row, $column . ' is neither 0 nor 1.'),
        };
    }

    public static function float(mixed $value, string $row, string $column): float
    {
        if (is_float($value) || is_int($value) || (is_string($value) && is_numeric($value))) {
            return (float) $value;
        }
        throw StoredAlertingRowUnreadable::because($row, $column . ' is not a number.');
    }
}
