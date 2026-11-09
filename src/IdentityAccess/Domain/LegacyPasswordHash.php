<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\IdentityAccess\Domain;

/**
 * Recognises a stored user_auth.password that local login still accepts as an
 * unsalted MD5 digest. compat_password_verify() in lib/auth.php falls back to
 * hash_equals(md5($password), $stored), and md5() returns 32 lowercase hex
 * digits, so exactly those values can match. password_hash() output starts
 * with "$" and never takes that form; uppercase hex, an empty value or any
 * other length can never log in through the fallback.
 */
final class LegacyPasswordHash
{
    public const int MD5_LENGTH = 32;

    private function __construct() {}

    public static function isUnsaltedMd5(string $stored): bool
    {
        return strlen($stored) === self::MD5_LENGTH && strspn($stored, '0123456789abcdef') === self::MD5_LENGTH;
    }
}
