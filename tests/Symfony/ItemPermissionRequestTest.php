<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Kadupul\IdentityAccess\Domain\ItemPermissionRequest;
use Kadupul\IdentityAccess\Domain\ItemType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ItemPermissionRequestTest extends TestCase
{
    /** @return iterable<string, array{?string, ?int, ?string}> user value => id, invalid value */
    public static function users(): iterable
    {
        yield 'omitted' => [null, null, null];
        yield 'empty, as a bare --user-id' => ['', null, null];
        yield 'zero' => ['0', null, null];
        yield 'negative' => ['-5', null, null];
        yield 'a decimal at or below zero' => ['-0.5', null, null];
        yield 'an id' => ['7', 7, null];
        yield 'an id with leading zeros' => ['007', 7, null];
        yield 'a decimal above zero names no account' => ['1.5', null, '1.5'];
        yield 'text names no account' => ['abc', null, 'abc'];
        yield 'a number followed by text names no account' => ['1abc', null, '1abc'];
        yield 'too many digits to be a row id' => ['9999999999999999999', null, '9999999999999999999'];
    }

    #[DataProvider('users')]
    public function testItReadsTheUserTheWayTheOriginalComparedIt(?string $value, ?int $id, ?string $invalid): void
    {
        $request = ItemPermissionRequest::parse($value, ItemType::Host, '5');

        self::assertSame([$id, $invalid], [$request->userId, $request->invalidUser]);
    }

    /** @return iterable<string, array{?string, ?int, ?string, bool}> */
    public static function items(): iterable
    {
        yield 'omitted' => [null, null, null, true];
        yield 'empty' => ['', null, null, true];
        yield 'zero' => ['0', null, null, true];
        yield 'zero with leading zeros' => ['00', null, null, true];
        yield 'a decimal zero' => ['0.0', null, null, true];
        yield 'an id' => ['5', 5, null, false];
        yield 'an id with leading zeros' => ['05', 5, null, false];
        yield 'negative names no row' => ['-1', null, '-1', false];
        yield 'text names no row' => ['abc', null, 'abc', false];
        yield 'a decimal names no row' => ['1.5', null, '1.5', false];
    }

    #[DataProvider('items')]
    public function testItReadsTheItemTheWayTheOriginalComparedIt(?string $value, ?int $id, ?string $invalid, bool $missing): void
    {
        $request = ItemPermissionRequest::parse('7', ItemType::Host, $value);

        self::assertSame([$id, $invalid, $missing], [$request->itemId, $request->invalidItem, $request->itemMissing()]);
    }

    public function testItNamesTypesByTheirOptionValue(): void
    {
        foreach (['graph' => 1, 'tree' => 2, 'host' => 3, 'graph_template' => 4] as $name => $id) {
            self::assertSame($id, ItemType::fromOption($name)?->value);
        }
        self::assertNull(ItemType::fromOption('user_admin'));
        self::assertNull(ItemType::fromOption(''));
    }
}
