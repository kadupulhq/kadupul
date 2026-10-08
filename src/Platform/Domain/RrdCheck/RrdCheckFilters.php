<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Domain\RrdCheck;

final readonly class RrdCheckFilters
{
    public const array KEYS = ['filter', 'rows', 'page', 'sort_column', 'sort_direction', 'age'];
    public const array SORTS = ['description', 'name_cache', 'local_data_id', 'message', 'test_date'];
    // Seconds. Zero lists problems recorded in the last two hours; any other
    // value lists problems at least that old, as the legacy page did.
    public const array AGES = [0, 14400, 43200, 86400, 259200, 604800];

    private function __construct(
        public string $filter,
        public int $page,
        public int $rows,
        public string $rowsParam,
        public string $sortColumn,
        public string $sortDirection,
        public int $age,
    ) {}

    public static function fromQuery(array $query, int $defaultRows): self
    {
        if (array_diff(array_keys($query), self::KEYS) !== []) {
            throw new \InvalidArgumentException('Invalid RRD check filters.');
        }
        foreach ($query as $value) {
            if (!is_string($value)) {
                throw new \InvalidArgumentException('Invalid RRD check filters.');
            }
        }
        $filter = $query['filter'] ?? '';
        $rowsText = $query['rows'] ?? '-1';
        $rows = $rowsText === '-1' ? $defaultRows : self::integer($rowsText, 1, 5000);
        $page = self::integer($query['page'] ?? '1', 1, 1000000);
        $age = self::integer($query['age'] ?? '0', 0, 604800);
        $sort = $query['sort_column'] ?? 'test_date';
        $direction = strtoupper($query['sort_direction'] ?? 'ASC');
        if (strlen($filter) > 200 || preg_match('//u', $filter) !== 1 || str_contains($filter, "\0")
            || !in_array($sort, self::SORTS, true) || !in_array($direction, ['ASC', 'DESC'], true)
            || !in_array($age, self::AGES, true) || $rows < 1) {
            throw new \InvalidArgumentException('Invalid RRD check filters.');
        }
        return new self($filter, $page, $rows, $rowsText, $sort, $direction, $age);
    }

    /** @return array<string, string> */
    public function query(): array
    {
        return [
            'filter' => $this->filter,
            'rows' => $this->rowsParam,
            'page' => (string) $this->page,
            'sort_column' => $this->sortColumn,
            'sort_direction' => $this->sortDirection,
            'age' => (string) $this->age,
        ];
    }

    private static function integer(string $value, int $minimum, int $maximum): int
    {
        if (preg_match('/\A[0-9]{1,7}\z/D', $value) !== 1) {
            throw new \InvalidArgumentException('Invalid RRD check filters.');
        }
        $parsed = (int) $value;
        if ($parsed < $minimum || $parsed > $maximum) {
            throw new \InvalidArgumentException('Invalid RRD check filters.');
        }
        return $parsed;
    }
}
