<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Kadupul\Graphing\Domain\GprintPresetFilters;
use PHPUnit\Framework\TestCase;

final class GprintPresetFiltersTest extends TestCase
{
    public function testItParsesLegacyFilterNamesAndPreservesDefaultRowsSelection(): void
    {
        $filters = GprintPresetFilters::fromQuery([
            'filter' => 'average %5', 'rows' => '-1', 'page' => '3', 'sort_column' => 'graphs',
            'sort_direction' => 'DESC', 'has_graphs' => 'true',
        ], 44);

        self::assertSame('average %5', $filters->filter);
        self::assertSame(44, $filters->rows);
        self::assertSame('-1', $filters->query()['rows']);
        self::assertSame(3, $filters->page);
        self::assertSame('DESC', $filters->sortDirection);
        self::assertTrue($filters->hasGraphs);
        self::assertSame('fresh <name>', GprintPresetFilters::fromQuery(['filter' => 'fresh <name>'], 25)->filter);
    }

    public function testItRejectsNestedValuesUnsupportedSortAndUnboundedPageSizes(): void
    {
        foreach ([
            [['filter' => ['bad']], 25],
            [['sort_column' => 'name;DROP TABLE'], 25],
            [['rows' => '999999'], 25],
            [['has_graphs' => 'anything'], 25],
            [['filter' => "bad\0filter"], 25],
        ] as [$query, $default]) {
            try {
                GprintPresetFilters::fromQuery($query, $default);
                self::fail('Invalid filter data was accepted.');
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }
}
