<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2004-2026 The Cacti Group
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace Kadupul\Navigation\Contract\Administration;

final readonly class ListFilters
{
    /** Legacy's own fallback when num_rows_table is unset or invalid. */
    public const int FALLBACK_ROWS = 25;

    /**
     * @param int $rows the effective page size
     * @param string $rowsParam the submitted value, '-1' when the configured default applies
     */
    private function __construct(public string $filter, public int $rows, public string $rowsParam, public int $page, public string $sortColumn, public string $sortDirection) {}

    /** @param array<string, mixed> $query */
    public static function fromQuery(ListScreen $screen, array $query, int $defaultRows = self::FALLBACK_ROWS): self
    {
        return self::fromColumns($screen->columns(), $query, $screen->defaultSortColumn(), $screen->defaultSortDirection(), $defaultRows);
    }

    /**
     * Legacy lists treat an absent rows value and rows=-1 as the configured
     * num_rows_table, and accept any explicit size from 1 to 5000.
     *
     * @param array<string, string> $columns
     * @param array<string, mixed> $query
     */
    public static function fromColumns(array $columns, array $query, string $defaultColumn = 'name', string $defaultDirection = 'ASC', int $defaultRows = self::FALLBACK_ROWS): self
    {
        if (array_diff(array_keys($query), ['filter', 'rows', 'page', 'sort_column', 'sort_direction']) !== []) {
            throw new \InvalidArgumentException('Invalid list filters.');
        }
        foreach ($query as $value) {
            if (!is_string($value)) {
                throw new \InvalidArgumentException('Invalid list filters.');
            }
        }
        $filter = $query['filter'] ?? '';
        $rowsParam = $query['rows'] ?? '-1';
        $page = $query['page'] ?? '1';
        $column = $query['sort_column'] ?? $defaultColumn;
        $direction = $query['sort_direction'] ?? $defaultDirection;
        if (!mb_check_encoding($filter, 'UTF-8') || mb_strlen($filter) > 255 || preg_match('/[\x00-\x1f\x7f]/', $filter)
            || ($rowsParam !== '-1' && (preg_match('/\A[1-9][0-9]{0,3}\z/D', $rowsParam) !== 1 || (int) $rowsParam > 5000))
            || preg_match('/\A[1-9][0-9]{0,4}\z/D', $page) !== 1
            || !array_key_exists($column, $columns) || !in_array($direction, ['ASC', 'DESC'], true)) {
            throw new \InvalidArgumentException('Invalid list filters.');
        }
        $rows = $rowsParam === '-1' ? $defaultRows : (int) $rowsParam;
        return new self($filter, $rows, $rowsParam, (int) $page, $column, $direction);
    }

    /** @return array<string, string> */
    public function query(): array
    {
        return ['filter' => $this->filter, 'rows' => $this->rowsParam, 'page' => (string) $this->page, 'sort_column' => $this->sortColumn, 'sort_direction' => $this->sortDirection];
    }
}
