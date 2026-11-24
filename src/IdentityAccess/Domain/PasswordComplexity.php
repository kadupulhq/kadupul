<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2004-2026 The Cacti Group
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace Kadupul\IdentityAccess\Domain;

/** Native byte-based rules, shared by login and password-setting workflows. */
final readonly class PasswordComplexity
{
    /** Retain PHP's comparison with the existing scalar minimum-length setting. */
    public function meetsMinimumLength(#[\SensitiveParameter] string $password, string|int|float|bool|null $minimum): bool
    {
        return !(strlen($password) < $minimum);
    }

    public function meetsNumberRequirement(#[\SensitiveParameter] string $password): bool
    {
        $remaining = str_replace(['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'], '', $password);
        // Three-way comparison retains native numeric-string comparison;
        // switching to byte equality would change existing policy outcomes.
        return ($remaining <=> $password) !== 0;
    }

    public function meetsCaseRequirement(#[\SensitiveParameter] string $password): bool
    {
        // The native setting checks ASCII lowercase conversion. Do not silently
        // replace it with Unicode case rules during the architecture migration.
        return (strtolower($password) <=> $password) !== 0;
    }

    public function meetsSpecialRequirement(#[\SensitiveParameter] string $password): bool
    {
        $remaining = str_replace(['~', '`', '!', '@', '#', '$', '%', '^', '&', '*', '(', ')', '-', '_', '+', '=', '[', '{', ']', '}', ';', ':', '<', ',', '.', '>', '?', '|', '/', '\\'], '', $password);
        return ($remaining <=> $password) !== 0;
    }
}
