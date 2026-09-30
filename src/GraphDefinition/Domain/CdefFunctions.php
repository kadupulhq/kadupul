<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace Kadupul\GraphDefinition\Domain;

final class CdefFunctions
{
    /** @var array<string, string> */
    public const FUNCTIONS = [
        '1' => 'SIN', '2' => 'COS', '3' => 'LOG', '4' => 'EXP', '5' => 'FLOOR', '6' => 'CEIL',
        '7' => 'LT', '8' => 'LE', '9' => 'GT', '10' => 'GE', '11' => 'EQ', '12' => 'IF',
        '13' => 'MIN', '14' => 'MAX', '15' => 'LIMIT', '16' => 'DUP', '17' => 'EXC', '18' => 'POP',
        '19' => 'UN', '20' => 'UNKN', '21' => 'PREV', '22' => 'INF', '23' => 'NEGINF', '24' => 'NOW',
        '25' => 'TIME', '26' => 'LTIME', '27' => 'ADDNAN', '28' => 'TREND', '29' => 'TRENDNAN',
        '30' => 'PREDICT', '31' => 'PREDICTSIGMA', '32' => 'PREDICTPERC', '33' => 'SQRT', '34' => 'ATAN',
        '35' => 'ATAN2', '36' => 'POW', '37' => 'ISINF', '38' => 'MINNAN', '39' => 'MAXNAN',
        '40' => 'DEG2RAD', '41' => 'RAD2DEG', '42' => 'ABS', '43' => 'REV', '44' => 'SMIN',
        '45' => 'SMAX', '46' => 'MEDIAN', '47' => 'STDEV', '48' => 'PERCENT', '49' => 'COUNT',
        '50' => 'STEPWIDTH', '51' => 'NEWDAY', '52' => 'NEWWEEK', '53' => 'NEWMONTH', '54' => 'NEWYEAR',
        '55' => 'DEPTH', '56' => 'COPY', '57' => 'INDEX', '58' => 'ROLL',
    ];

    /** @return array<string, string> */
    public static function functions(bool $roundSupported): array
    {
        return $roundSupported ? self::FUNCTIONS + ['59' => 'ROUND'] : self::FUNCTIONS;
    }

    /** @var array<string, string> */
    public const OPERATORS = ['1' => '+', '2' => '-', '3' => '*', '4' => '/', '5' => '%'];

    /** @var array<string, string> */
    public const DATA_SOURCES = [
        'CURRENT_DATA_SOURCE' => 'Current Graph Item Data Source',
        'CURRENT_DATA_SOURCE_PI' => 'Current Graph Item Polling Interval',
        'ALL_DATA_SOURCES_NODUPS' => 'All Data Sources (Do not Include Duplicates)',
        'ALL_DATA_SOURCES_DUPS' => 'All Data Sources (Include Duplicates)',
        'SIMILAR_DATA_SOURCES_NODUPS' => 'All Similar Data Sources (Do not Include Duplicates)',
        'SIMILAR_DATA_SOURCES_NODUPS_PI' => 'All Similar Data Sources (Do not Include Duplicates) Polling Interval',
        'SIMILAR_DATA_SOURCES_DUPS' => 'All Similar Data Sources (Include Duplicates)',
        'CURRENT_DS_MINIMUM_VALUE' => 'Current Data Source Item: Minimum Value',
        'CURRENT_DS_MAXIMUM_VALUE' => 'Current Data Source Item: Maximum Value',
        'CURRENT_GRAPH_MINIMUM_VALUE' => 'Graph: Lower Limit',
        'CURRENT_GRAPH_MAXIMUM_VALUE' => 'Graph: Upper Limit',
        'COUNT_ALL_DS_NODUPS' => 'Count of All Data Sources (Do not Include Duplicates)',
        'COUNT_ALL_DS_DUPS' => 'Count of All Data Sources (Include Duplicates)',
        'COUNT_SIMILAR_DS_NODUPS' => 'Count of All Similar Data Sources (Do not Include Duplicates)',
        'COUNT_SIMILAR_DS_DUPS' => 'Count of All Similar Data Sources (Include Duplicates)',
    ];

    /** @var array<int, string> */
    public const TYPES = [1 => 'Function', 2 => 'Operator', 4 => 'Special Data Source', 5 => 'Another CDEF', 6 => 'Custom String'];

    public static function itemLabel(int $type, string $value, ?string $cdefName = null, bool $roundSupported = false): string
    {
        return match ($type) {
            1 => self::functions($roundSupported)[$value] ?? $value,
            2 => self::OPERATORS[$value] ?? $value,
            4 => self::DATA_SOURCES[$value] ?? $value,
            5 => $cdefName ?? $value,
            default => $value,
        };
    }

    public static function rrdValue(int $type, string $value, bool $roundSupported = false): string
    {
        return match ($type) {
            1 => self::functions($roundSupported)[$value] ?? $value,
            2 => self::OPERATORS[$value] ?? $value,
            default => $value,
        };
    }
}
