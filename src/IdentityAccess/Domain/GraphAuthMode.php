<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\IdentityAccess\Domain;

/**
 * Installation graph/device authorization mode stored in settings.graph_auth_method.
 * Matches VisibilitySql modes 1 through 4.
 */
enum GraphAuthMode: int
{
    case Permissive = 1;
    case Restrictive = 2;
    case DeviceOnly = 3;
    case GraphTemplateOnly = 4;

    public static function fromStored(mixed $value): self
    {
        if ($value === false || $value === null || $value === '') {
            return self::Permissive;
        }
        if (is_int($value) || (is_string($value) && ctype_digit($value))) {
            $parsed = self::tryFrom((int) $value);
            if ($parsed !== null) {
                return $parsed;
            }
        }

        throw new \InvalidArgumentException('Unsupported graph authorization mode.');
    }
}
