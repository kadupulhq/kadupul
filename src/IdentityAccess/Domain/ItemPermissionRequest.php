<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\IdentityAccess\Domain;

/**
 * What add_perms.php was asked for, read the way the script read it. Its ids
 * arrived as strings and were compared with PHP's loose operators and then
 * interpolated into SQL, so this keeps the outcomes those comparisons had:
 * a zero or negative number named nothing, a non-number named a missing row.
 */
final readonly class ItemPermissionRequest
{
    /** The largest id read as a number; a longer digit string cannot name a row. */
    private const int MAX_DIGITS = 18;

    /**
     * @param ?int $userId null when no user was named
     * @param ?string $invalidUser the user value as given, when it names no possible account
     * @param ?int $itemId null when none was named or it cannot name a row
     * @param ?string $invalidItem the item value as given, when it names no possible row
     */
    private function __construct(
        public ?int $userId,
        public ?string $invalidUser,
        public ?ItemType $type,
        public ?int $itemId,
        public ?string $invalidItem,
    ) {}

    public static function parse(?string $user, ?ItemType $type, ?string $item): self
    {
        [$userId, $invalidUser] = self::user($user);
        [$itemId, $invalidItem] = self::item($item);

        return new self($userId, $invalidUser, $type, $itemId, $invalidItem);
    }

    /** True when the item was left out, or was a number equal to zero. */
    public function itemMissing(): bool
    {
        return $this->itemId === null && $this->invalidItem === null;
    }

    /** A whole, positive row id, or null for anything else. */
    public static function wholeId(string $value): ?int
    {
        return ctype_digit($value) && strlen($value) <= self::MAX_DIGITS && (int) $value > 0 ? (int) $value : null;
    }

    /** @return array{0: ?int, 1: ?string} */
    private static function user(?string $value): array
    {
        // `$userId > 0` was false for '' and for any number up to zero.
        if ($value === null || $value === '' || (is_numeric($value) && (float) $value <= 0)) {
            return [null, null];
        }

        return [self::wholeId($value), self::wholeId($value) === null ? $value : null];
    }

    /** @return array{0: ?int, 1: ?string} */
    private static function item(?string $value): array
    {
        // `$itemId == 0` was true for '' and for any number equal to zero.
        if ($value === null || $value === '' || (is_numeric($value) && (float) $value === 0.0)) {
            return [null, null];
        }

        return [self::wholeId($value), self::wholeId($value) === null ? $value : null];
    }
}
