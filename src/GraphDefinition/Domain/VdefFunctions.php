<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\GraphDefinition\Domain;

final class VdefFunctions
{
    public const FUNCTIONS = [
        '1' => 'MAXIMUM',
        '2' => 'MINIMUM',
        '3' => 'AVERAGE',
        '4' => 'STDEV',
        '5' => 'LAST',
        '6' => 'FIRST',
        '7' => 'TOTAL',
        '8' => 'PERCENT',
        '9' => 'PERCENTNAN',
        '10' => 'LSLSLOPE',
        '11' => 'LSLINT',
        '12' => 'LSLCORREL',
    ];

    public const TYPES = ['1' => 'Function', '4' => 'Special Data Source', '6' => 'Custom String'];

    public const DATA_SOURCES = ['CURRENT_DATA_SOURCE' => 'Current Graph Item Data Source'];

    public static function itemLabel(int $type, string $value): string
    {
        return match ($type) {
            1 => self::FUNCTIONS[$value] ?? $value,
            4 => self::DATA_SOURCES[$value] ?? $value,
            default => $value,
        };
    }

    public static function rrdValue(int $type, string $value): string
    {
        return $type === 1 ? (self::FUNCTIONS[$value] ?? $value) : $value;
    }
}
