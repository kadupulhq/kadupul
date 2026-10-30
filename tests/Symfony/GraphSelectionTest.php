<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Kadupul\Graphing\Domain\GraphSelection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class GraphSelectionTest extends TestCase
{
    /** @return iterable<string, array{string, ?list<int>}> */
    public static function accepted(): iterable
    {
        yield 'all' => ['all', null];
        yield 'ALL in any case' => ['ALL', null];
        yield 'device 0' => ['0', [0]];
        yield 'one device' => ['12', [12]];
        yield 'a list' => ['3,1,2', [3, 1, 2]];
        yield 'the largest id' => ['4294967295', [4294967295]];
    }

    /** @param ?list<int> $ids */
    #[DataProvider('accepted')]
    public function testItParsesWhatReapplyNamesAccepted(string $hosts, ?array $ids): void
    {
        $selection = GraphSelection::parse($hosts, 'cpu');

        self::assertNotNull($selection);
        self::assertSame($ids, $selection->hostIds);
        self::assertSame('cpu', $selection->filter);
    }

    /** @return iterable<string, array{string}> */
    public static function refused(): iterable
    {
        yield 'empty' => [''];
        yield 'zero in a list' => ['0,1'];
        yield 'negative' => ['-1'];
        yield 'decimal' => ['1.5'];
        yield 'exponent' => ['1e3'];
        yield 'padded' => [' 1'];
        yield 'trailing comma' => ['1,'];
        yield 'past the column' => ['4294967296'];
        yield 'text' => ['all,1'];
    }

    #[DataProvider('refused')]
    public function testItRefusesEverythingElse(string $hosts): void
    {
        self::assertNull(GraphSelection::parse($hosts));
    }
}
