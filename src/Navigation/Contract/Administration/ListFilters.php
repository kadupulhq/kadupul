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
    private function __construct(public string $filter, public int $rows, public int $page, public string $sortColumn, public string $sortDirection) {}

    /** @param array<string, mixed> $query */
    public static function fromQuery(ListScreen $screen, array $query): self
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
        $rows = $query['rows'] ?? '25';
        $page = $query['page'] ?? '1';
        $column = $query['sort_column'] ?? $screen->defaultSortColumn();
        $direction = $query['sort_direction'] ?? $screen->defaultSortDirection();
        if (!mb_check_encoding($filter, 'UTF-8') || mb_strlen($filter) > 255 || preg_match('/[\x00-\x1f\x7f]/', $filter)
            || !in_array($rows, ['10', '25', '50', '100'], true)
            || preg_match('/\A[1-9][0-9]{0,4}\z/D', $page) !== 1
            || !array_key_exists($column, $screen->columns()) || !in_array($direction, ['ASC', 'DESC'], true)) {
            throw new \InvalidArgumentException('Invalid list filters.');
        }
        return new self($filter, (int) $rows, (int) $page, $column, $direction);
    }

    /** @return array<string, string> */
    public function query(): array
    {
        return ['filter' => $this->filter, 'rows' => (string) $this->rows, 'page' => (string) $this->page, 'sort_column' => $this->sortColumn, 'sort_direction' => $this->sortDirection];
    }
}
