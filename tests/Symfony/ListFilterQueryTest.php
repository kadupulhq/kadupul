<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Kadupul\Graphing\Domain\ColorTemplateFilters;
use Kadupul\Graphing\Domain\DataSourceProfileFilters;
use Kadupul\Graphing\Domain\GprintPresetFilters;
use Kadupul\Graphing\Domain\ListFilterQuery;
use Kadupul\Graphing\Domain\PaletteColorFilters;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ListFilterQueryTest extends TestCase
{
    public function testByteListsAndCharacterListsKeepTheirOwnFilterLimit(): void
    {
        $wide = str_repeat('界', 67);
        self::assertSame($wide, ColorTemplateFilters::fromQuery(['filter' => $wide], 25)->filter);
        self::assertSame($wide, DataSourceProfileFilters::fromQuery(['filter' => $wide], 25)->filter);
        foreach ([fn() => GprintPresetFilters::fromQuery(['filter' => $wide], 25), fn() => PaletteColorFilters::fromQuery(['filter' => $wide], 25)] as $parse) {
            try {
                $parse();
                self::fail('A 201-byte filter was accepted by a byte-limited list.');
            } catch (\InvalidArgumentException $error) {
                self::assertContains($error->getMessage(), ['Invalid GPRINT preset filters.', 'Invalid color filters.']);
            }
        }
        self::assertSame(str_repeat('a', 200), GprintPresetFilters::fromQuery(['filter' => str_repeat('a', 200)], 25)->filter);
    }

    public function testEveryFlagIsCheckedAndDefaulted(): void
    {
        $palette = PaletteColorFilters::fromQuery([], 30, true);
        self::assertTrue($palette->hasGraphs);
        self::assertTrue($palette->named);
        self::assertSame(['filter' => '', 'rows' => '-1', 'page' => 1, 'sort_column' => 'name', 'sort_direction' => 'ASC', 'has_graphs' => 'true', 'named' => 'true'], $palette->query());
        self::assertSame(30, $palette->rows);
        self::assertSame(['filter' => '', 'rows' => '-1', 'page' => '1', 'sort_column' => 'step', 'sort_direction' => 'ASC', 'has_data' => 'false'], DataSourceProfileFilters::fromQuery([], 25)->query());
        $this->expectExceptionObject(new \InvalidArgumentException('Invalid color filters.'));
        PaletteColorFilters::fromQuery(['has_graphs' => 'true', 'named' => 'yes'], 25);
    }

    /** @param array<string, mixed> $query */
    #[DataProvider('rejected')]
    public function testRejectedQueriesUseTheCallerMessage(array $query): void
    {
        $this->expectExceptionObject(new \InvalidArgumentException('Fixture rejected.'));
        ListFilterQuery::parse($query, ['filter', 'rows', 'page', 'sort_column', 'sort_direction', 'flag'], ['name'], 'name', ['flag' => false], 25, false, 'Fixture rejected.');
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function rejected(): iterable
    {
        yield 'unknown key' => [['other' => '1']];
        yield 'array value' => [['filter' => ['x']]];
        yield 'page zero' => [['page' => '0']];
        yield 'page too large' => [['page' => '1000001']];
        yield 'rows too large' => [['rows' => '5001']];
        yield 'signed rows' => [['rows' => '+5']];
        yield 'unknown sort' => [['sort_column' => 'hex']];
        yield 'bad direction' => [['sort_direction' => 'up']];
        yield 'bad flag' => [['flag' => 'on']];
        yield 'NUL in filter' => [['filter' => "a\0b"]];
        yield 'invalid UTF-8' => [['filter' => "\xff"]];
    }
}
