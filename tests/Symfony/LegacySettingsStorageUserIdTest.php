<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Kadupul\Platform\Infrastructure\Legacy\LegacySettingsStorage;
use PHPUnit\Framework\TestCase;

/**
 * The user id rule the settings_user functions in lib/functions.php share.
 * tests/Unit/Database/DbLayerHardeningTest covers each function's use of it.
 */
final class LegacySettingsStorageUserIdTest extends TestCase
{
    private const int MAX = 16777215;

    public function testItAcceptsAnIntOrDigitStringWithinTheColumn(): void
    {
        foreach (self::accepted() as $id) {
            self::assertTrue(LegacySettingsStorage::acceptsUserId($id), var_export($id, true));
        }
    }

    public function testItRejectsEverythingTheColumnWouldCoerce(): void
    {
        foreach (self::rejected() as $id) {
            self::assertFalse(LegacySettingsStorage::acceptsUserId($id), get_debug_type($id) . ' ' . (is_scalar($id) ? var_export($id, true) : ''));
        }
    }

    /** set_user_setting() and save_user_settings() checked ids with this expression before they shared the method. */
    public function testItMatchesTheExpressionItReplaced(): void
    {
        $previous = static fn(mixed $user): bool => (bool) ((is_int($user) && $user >= 0 && $user <= 16777215) || (is_string($user) && preg_match('/^[0-9]+$/D', $user) && strlen(ltrim($user, '0')) <= 8 && (int) $user <= 16777215));

        foreach (array_merge(self::accepted(), self::rejected()) as $id) {
            self::assertSame($previous($id), LegacySettingsStorage::acceptsUserId($id), get_debug_type($id) . ' ' . (is_scalar($id) ? var_export($id, true) : ''));
        }
    }

    /** @return list<int|string> */
    private static function accepted(): array
    {
        return [0, 1, self::MAX, '0', '00', '5', '016777215', (string) self::MAX, str_repeat('0', 40) . '5', str_repeat('0', 40)];
    }

    /** @return list<mixed> */
    private static function rejected(): array
    {
        return [
            -1, -2, self::MAX + 1, PHP_INT_MAX, PHP_INT_MIN,
            (string) (self::MAX + 1), '0' . (self::MAX + 1), str_repeat('9', 40),
            "5\n", ' 5', '5 ', '+5', '-5', '5.0', '1e3', '0x1', "\u{FF15}", "\u{0665}", '', 'abc', '1abc',
            5.0, NAN, INF, true, false, null, [5], new \stdClass(),
        ];
    }
}
