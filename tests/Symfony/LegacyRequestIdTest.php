<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Kadupul\Platform\Contract\LegacyRequestId;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class LegacyRequestIdTest extends TestCase
{
    public function testAnEmptyIdIsZeroAndDigitsAreRead(): void
    {
        self::assertSame(0, LegacyRequestId::parse('', 'bad'));
        self::assertSame(0, LegacyRequestId::parse('0', 'bad'));
        self::assertSame(99999999, LegacyRequestId::parse('99999999', 'bad'));
        self::assertSame(4294967295, LegacyRequestId::parse('4294967295', 'bad', 10));
    }

    /** @return iterable<string, array{mixed, int<1, 18>}> */
    public static function malformed(): iterable
    {
        yield 'padded' => ['01', 8];
        yield 'signed' => ['-1', 8];
        yield 'exponent' => ['1e3', 8];
        yield 'decimal' => ['1.0', 8];
        yield 'spaced' => ['1 ', 8];
        yield 'trailing newline' => ["1\n", 8];
        yield 'too long' => ['100000000', 8];
        yield 'too long for ten' => ['10000000000', 10];
        yield 'integer' => [1, 8];
        yield 'array' => [['1'], 8];
        yield 'null' => [null, 8];
    }

    /** @param int<1, 18> $digits */
    #[DataProvider('malformed')]
    public function testAMalformedIdIsRefusedWithTheCallersMessage(mixed $raw, int $digits): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid request.');
        LegacyRequestId::parse($raw, 'Invalid request.', $digits);
    }
}
