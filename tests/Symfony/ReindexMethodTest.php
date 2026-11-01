<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Kadupul\Graphing\Domain\ReindexMethod;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ReindexMethodTest extends TestCase
{
    /** @return iterable<string, array{string, int|string, string, string}> typed text, stored value, printed text, name */
    public static function accepted(): iterable
    {
        yield 'a plain digit' => ['2', 2, '2', 'Index Count'];
        yield 'zero' => ['0', 0, '0', 'None'];
        yield 'the name none' => ['none', 0, '0', 'None'];
        yield 'the name in capitals' => ['UPTIME', 1, '1', 'Uptime'];
        yield 'the name in mixed case' => ['Fields', 3, '3', 'Verify All'];
        yield 'the name index' => ['index', 2, '2', 'Index Count'];
        yield 'a leading zero keeps its spelling and has no name' => ['01', '01', '01', ''];
        yield 'a decimal keeps its spelling and has no name' => ['1.5', '1.5', '1.5', ''];
        yield 'a float written whole' => ['1e0', '1e0', '1e0', ''];
        yield 'a padded digit' => [' 1', ' 1', ' 1', ''];
        yield 'a negative zero' => ['-0', '-0', '-0', ''];
        yield 'the top of the range' => ['3.0', '3.0', '3.0', ''];
    }

    #[DataProvider('accepted')]
    public function testItReadsTheMethodAsTheScriptDid(string $typed, int|string $value, string $printed, string $name): void
    {
        $method = ReindexMethod::parse($typed);

        self::assertNotNull($method);
        self::assertSame([$value, $printed, $name], [$method->value, $method->printed(), $method->name()]);
    }

    /** @return iterable<string, array{string}> */
    public static function refused(): iterable
    {
        yield 'empty' => [''];
        yield 'above the range' => ['4'];
        yield 'a fraction above the range' => ['3.5'];
        yield 'below the range' => ['-1'];
        yield 'an unknown name' => ['bogus'];
        yield 'a padded name' => [' none'];
        yield 'hexadecimal' => ['0x1'];
        yield 'a float past the range' => ['1e1'];
        yield 'not a number' => ['INF'];
    }

    #[DataProvider('refused')]
    public function testItRefusesWhatTheScriptRefused(string $typed): void
    {
        self::assertNull(ReindexMethod::parse($typed));
    }
}
