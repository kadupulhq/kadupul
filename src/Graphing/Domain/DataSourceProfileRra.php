<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Graphing\Domain;

/** One round-robin archive of a profile. Steps counts polling intervals per row. */
final readonly class DataSourceProfileRra
{
    public const int MAX_NAME = 255;

    /** data_source_profiles_rra.rows is an unsigned int. */
    public const int MAX_ROWS = 4294967295;

    /** Choices of the legacy Aggregation Level drop-down, in seconds; 1 means one row per sample. */
    public const array AGGREGATION_LEVELS = [1, 30, 60, 300, 600, 900, 1800, 3600, 7200, 10800, 14400, 21600, 43200, 86400, 604800];

    /** Choices of the legacy Default Timespan drop-down, in seconds. */
    public const array TIMESPANS = [3600, 7200, 14400, 21600, 43200, 86400, 172800, 345600, 604800, 1209600, 1814400, 2618784, 5237568, 7856352, 10475136, 15712704, 31536000, 63072000, 94608000, 126144000, 157680000, 189216000, 220752000, 252288000, 283824000, 315360000];

    public string $revision;

    public function __construct(public int $id, public int $profileId, public string $name, public int $steps, public int $rows, public int $timespan)
    {
        $this->revision = hash('sha256', json_encode([$id, $profileId, $name, $steps, $rows, $timespan], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }

    /** The legacy RRA name may be empty; the column holds 255 characters. */
    public static function validateName(string $name): void
    {
        if (mb_strlen($name, 'UTF-8') > self::MAX_NAME || preg_match('//u', $name) !== 1 || preg_match('/[\x00\r\n]/', $name) === 1) {
            throw new \InvalidArgumentException('Enter an RRA name of at most 255 characters.');
        }
    }

    public static function validateRows(int $rows): void
    {
        if ($rows < 1 || $rows > self::MAX_ROWS) {
            throw new \InvalidArgumentException('Enter a number of rows from 1 to 4294967295.');
        }
    }

    /**
     * The form submits the aggregation level in seconds. Legacy divided it by
     * the step and let the database round the quotient; only whole multiples
     * describe an archive RRDtool can build.
     */
    public static function stepsFor(int $levelSeconds, int $step): int
    {
        if ($levelSeconds === 1) {
            return 1;
        }
        if ($levelSeconds < 1 || $step < 1 || $levelSeconds % $step !== 0) {
            throw new \InvalidArgumentException('Choose an aggregation level that is a whole multiple of the polling interval.');
        }
        return intdiv($levelSeconds, $step);
    }
}
