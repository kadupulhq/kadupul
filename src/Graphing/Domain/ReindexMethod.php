<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Graphing\Domain;

/**
 * The reindex method of add_data_query.php, read as that script read it: a
 * number from 0 to 3 in any numeric spelling, or one of the names none, uptime,
 * index and fields in any case. A number is kept as typed, because the script
 * printed it and put it into its SQL unchanged, so "1.5" stayed "1.5".
 */
final readonly class ReindexMethod
{
    private const array NAMES = [0 => 'None', 1 => 'Uptime', 2 => 'Index Count', 3 => 'Verify All'];
    private const array BY_NAME = ['none' => 0, 'uptime' => 1, 'index' => 2, 'fields' => 3];

    /** @param int|string $value the method as stored: an int for a name or a plain digit, else the numeric text as typed */
    private function __construct(public int|string $value) {}

    /** @return ?self null when $typed is neither a number from 0 to 3 nor a method name */
    public static function parse(string $typed): ?self
    {
        if (is_numeric($typed) && $typed >= 0 && $typed <= 3) {
            // PHP turned only a plain digit into an array key; "01" or "1.0" found no name.
            return new self(preg_match('/^[0-3]$/', $typed) === 1 ? (int) $typed : $typed);
        }
        $method = self::BY_NAME[strtolower($typed)] ?? null;

        return $method === null ? null : new self($method);
    }

    /** The name the script printed in brackets; empty for a number with no name. */
    public function name(): string
    {
        return is_int($this->value) ? self::NAMES[$this->value] : '';
    }

    /** What the script printed for the method. */
    public function printed(): string
    {
        return (string) $this->value;
    }
}
