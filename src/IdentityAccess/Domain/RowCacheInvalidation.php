<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\IdentityAccess\Domain;

final readonly class RowCacheInvalidation
{
    public function __construct(public string $class, public int $before)
    {
        if ($class === '' || strlen($class) > 20 || $before < 0) {
            throw new \InvalidArgumentException('Invalid row-cache invalidation.');
        }
    }
}
