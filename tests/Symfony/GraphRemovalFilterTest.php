<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Kadupul\Graphing\Domain\GraphRemovalFilter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class GraphRemovalFilterTest extends TestCase
{
    /** @return iterable<string, array{string, ?int}> */
    public static function ids(): iterable
    {
        yield 'plain' => ['12', 12];
        yield 'exponent as is_numeric reads it' => ['1e2', 100];
        yield 'decimal truncates' => ['1.5', 1];
        yield 'zero' => ['0', null];
        yield 'negative' => ['-3', null];
        yield 'words' => ['abc', null];
        yield 'empty, what a bare --host-id gave' => ['', null];
        yield 'trailing text' => ['5x', null];
    }

    #[DataProvider('ids')]
    public function testAnIdIsWhatIsNumericAndAboveZero(string $value, ?int $expected): void
    {
        self::assertSame($expected, GraphRemovalFilter::id($value));
    }

    /** @return iterable<string, array{string, ?string}> */
    public static function patterns(): iterable
    {
        yield 'plain' => ['^Core', '^Core'];
        yield 'alternation, braces and NUL are dropped as db_qstr_rlike dropped them' => ["a|b{2}\0c", 'ab2c'];
        yield 'exactly 50 bytes' => [str_repeat('a', 50), str_repeat('a', 50)];
        yield 'over 50 bytes' => [str_repeat('a', 51), null];
        yield 'semicolon' => ['a;b', null];
        yield 'empty matches every title' => ['', null];
        yield 'only characters that are dropped' => ['||', null];
    }

    #[DataProvider('patterns')]
    public function testAPatternIsCleanedAndRefusedWhenItWouldMatchEverythingOrBreakTheLimits(string $value, ?string $expected): void
    {
        self::assertSame($expected, GraphRemovalFilter::pattern($value));
    }

    public function testNoSelectorMatchesEverythingUnlessASelectorIsGiven(): void
    {
        self::assertTrue((new GraphRemovalFilter([], [], [], [], false))->matchesEverything());
        self::assertTrue((new GraphRemovalFilter([1], [], [], ['a'], true))->matchesEverything());
        self::assertFalse((new GraphRemovalFilter([], [], [4], [], false))->matchesEverything());
        self::assertFalse((new GraphRemovalFilter([], [], [], ['a'], false))->matchesEverything());
    }
}
