<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2004-2026 The Cacti Group
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace Kadupul\Navigation\Contract\Management;

final readonly class CatalogPage
{
    /**
     * @param list<array<string, string|int|float|null>> $records
     * @param array<string, int|string>|null $operationParameters
     */
    public function __construct(public array $records, public ?string $parentName = null, public ?array $operationParameters = null) {}

    /** @param list<array<string, mixed>> $rows */
    public static function fromRows(array $rows, mixed $parentName): self
    {
        if ($parentName !== null && !is_string($parentName)) {
            throw new \UnexpectedValueException('Invalid catalog parent name.');
        }
        $records = [];
        foreach ($rows as $row) {
            $record = [];
            foreach ($row as $column => $value) {
                if ($value !== null && !is_string($value) && !is_int($value) && !is_float($value)) {
                    throw new \UnexpectedValueException('Invalid catalog value.');
                }
                $record[$column] = $value;
            }
            $records[] = $record;
        }
        return new self($records, $parentName ?? '');
    }
}
