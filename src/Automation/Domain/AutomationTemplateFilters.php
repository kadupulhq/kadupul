<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Automation\Domain;

/** The legacy list had no sort; rows always follow the discovery match order. */
final readonly class AutomationTemplateFilters
{
    public const array KEYS = ['filter', 'rows', 'page'];

    private function __construct(
        public string $filter,
        public int $page,
        public int $rows,
        public string $rowsParam,
    ) {}

    /** @param array<string, mixed> $query */
    public static function fromQuery(array $query, int $defaultRows): self
    {
        if (array_diff(array_keys($query), self::KEYS) !== []) {
            throw new \InvalidArgumentException('Invalid automation template filters.');
        }
        foreach ($query as $value) {
            if (!is_string($value)) {
                throw new \InvalidArgumentException('Invalid automation template filters.');
            }
        }
        $filter = $query['filter'] ?? '';
        $page = self::integer($query['page'] ?? '1', 1, 1000000);
        $rowsText = $query['rows'] ?? '-1';
        $rows = $rowsText === '-1' ? $defaultRows : self::integer($rowsText, 1, 5000);
        if (strlen($filter) > 200 || preg_match('//u', $filter) !== 1 || str_contains($filter, "\0") || $rows < 1) {
            throw new \InvalidArgumentException('Invalid automation template filters.');
        }

        return new self($filter, $page, $rows, $rowsText);
    }

    private static function integer(string $value, int $minimum, int $maximum): int
    {
        if (preg_match('/\A[0-9]{1,7}\z/D', $value) !== 1) {
            throw new \InvalidArgumentException('Invalid automation template filters.');
        }
        $parsed = (int) $value;
        if ($parsed < $minimum || $parsed > $maximum) {
            throw new \InvalidArgumentException('Invalid automation template filters.');
        }

        return $parsed;
    }

    /** @return array<string, string> */
    public function query(): array
    {
        return ['filter' => $this->filter, 'rows' => $this->rowsParam, 'page' => (string) $this->page];
    }
}
