<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Graphing\Domain;

final readonly class DataSourceProfileFilters
{
    public const array KEYS = ['filter', 'rows', 'page', 'sort_column', 'sort_direction', 'has_data'];

    private const array SORTS = ['name', 'step', 'heartbeat', 'data_sources', 'templates'];

    private function __construct(
        public string $filter,
        public int $page,
        public int $rows,
        public string $rowsParam,
        public string $sortColumn,
        public string $sortDirection,
        public bool $hasData,
    ) {}

    /** @param array<string, mixed> $query */
    public static function fromQuery(array $query, int $defaultRows, bool $defaultHasData = false): self
    {
        if (array_diff(array_keys($query), self::KEYS) !== []) {
            throw new \InvalidArgumentException('Invalid profile filters.');
        }
        foreach ($query as $value) {
            if (!is_string($value)) {
                throw new \InvalidArgumentException('Invalid profile filters.');
            }
        }
        $filter = $query['filter'] ?? '';
        $page = self::integer($query['page'] ?? '1', 1, 1000000);
        $rowsText = $query['rows'] ?? '-1';
        $rows = $rowsText === '-1' ? $defaultRows : self::integer($rowsText, 1, 5000);
        // The legacy list sorted by polling interval until a column was chosen.
        $sort = $query['sort_column'] ?? 'step';
        $direction = strtoupper($query['sort_direction'] ?? 'ASC');
        $hasData = $query['has_data'] ?? ($defaultHasData ? 'true' : 'false');
        if (mb_strlen($filter, 'UTF-8') > 200 || preg_match('//u', $filter) !== 1 || str_contains($filter, "\0")
            || !in_array($sort, self::SORTS, true) || !in_array($direction, ['ASC', 'DESC'], true)
            || !in_array($hasData, ['true', 'false'], true) || $rows < 1) {
            throw new \InvalidArgumentException('Invalid profile filters.');
        }

        return new self($filter, $page, $rows, $rowsText, $sort, $direction, $hasData === 'true');
    }

    private static function integer(string $value, int $minimum, int $maximum): int
    {
        if (preg_match('/\A[0-9]{1,7}\z/D', $value) !== 1) {
            throw new \InvalidArgumentException('Invalid profile filters.');
        }
        $parsed = (int) $value;
        if ($parsed < $minimum || $parsed > $maximum) {
            throw new \InvalidArgumentException('Invalid profile filters.');
        }

        return $parsed;
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
            'has_data' => $this->hasData ? 'true' : 'false',
        ];
    }
}
