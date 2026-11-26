<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\IdentityAccess\Domain;

/**
 * Default policy stored on user_auth and user_auth_group policy_* columns.
 * Allow (1) means all items are visible except listed exceptions; Deny (2)
 * means no items are visible except listed exceptions.
 */
enum VisibilityDefault: int
{
    case Allow = 1;
    case Deny = 2;

    public static function fromStored(mixed $value): self
    {
        if (is_int($value) || (is_string($value) && ctype_digit($value))) {
            $parsed = self::tryFrom((int) $value);
            if ($parsed !== null) {
                return $parsed;
            }
        }

        throw new \InvalidArgumentException('Unsupported visibility default policy.');
    }
}
