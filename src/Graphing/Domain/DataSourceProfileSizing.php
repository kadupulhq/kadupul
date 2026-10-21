<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Graphing\Domain;

/** The size and retention figures the legacy profile form computed with get_size() and get_span(). */
final class DataSourceProfileSizing
{
    /** x86_64 RRDfile layout: file header, per data source header, one double per row and function. */
    public const int FILE_HEADER_BYTES = 284;
    public const int DATA_SOURCE_HEADER_BYTES = 300;
    public const int ROW_BYTES = 8;

    private const array UNITS = ['year' => 31536000, 'month' => 2592000, 'week' => 604800, 'day' => 86400];

    /**
     * Calendar parts of a duration. Like get_span(), a unit is taken only when
     * the remainder is strictly longer than one of it, so exactly one day is
     * "24 Hours", and minutes and seconds are dropped.
     *
     * @return list<array{unit: string, count: int}>
     */
    public static function span(int $seconds): array
    {
        $parts = [];
        foreach (self::UNITS as $unit => $length) {
            if ($seconds > $length) {
                $parts[] = ['unit' => $unit, 'count' => intdiv($seconds, $length)];
                $seconds %= $length;
            }
        }
        if ($seconds >= 3600) {
            $parts[] = ['unit' => 'hour', 'count' => intdiv($seconds, 3600)];
        }
        return $parts;
    }

    /** Seconds covered by an archive, or null when the product does not fit an integer. */
    public static function retention(int $step, int $steps, int $rows): ?int
    {
        $seconds = $step * $steps * $rows;
        return is_int($seconds) && $seconds >= 0 ? $seconds : null;
    }

    /** Kilobytes per data source for every archive of a profile. */
    public static function profileKilobytes(int $rows, int $functions): float
    {
        return ($rows * self::ROW_BYTES * $functions + self::DATA_SOURCE_HEADER_BYTES) / 1000;
    }

    /** Kilobytes per data source for one archive. */
    public static function archiveKilobytes(int $rows, int $functions): float
    {
        return ($rows * self::ROW_BYTES * $functions) / 1000;
    }
}
