<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Navigation\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;
use Kadupul\Navigation\Application\Port\ListPageSize;
use Kadupul\Navigation\Contract\Administration\ListFilters;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/** Reads num_rows_table the way read_config_option() does for legacy list pages. */
#[AsAlias(ListPageSize::class)]
final readonly class DbalListPageSize implements ListPageSize
{
    public function __construct(
        #[Autowire(service: 'doctrine.dbal.web_connection')]
        private Connection $database,
    ) {}

    public function defaultRows(): int
    {
        $rows = filter_var(
            $this->database->fetchOne("SELECT value FROM settings WHERE name = 'num_rows_table'"),
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1, 'max_range' => 5000]],
        );

        return $rows === false ? ListFilters::FALLBACK_ROWS : $rows;
    }
}
