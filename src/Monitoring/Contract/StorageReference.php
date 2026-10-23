<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Monitoring\Contract;

/**
 * Names the stored series file a binding points at, without saying where it
 * is. Only a Kadupul infrastructure adapter that already reads the legacy
 * data-source path may turn it into a file; it never appears in a collection
 * plan. Callers compare it and pass it on; they do not parse it.
 */
final readonly class StorageReference
{
    public function __construct(private string $value)
    {
        if (preg_match('/\A[a-z][a-z0-9-]*:[A-Za-z0-9._-]+\z/', $value) !== 1) {
            throw new \InvalidArgumentException(sprintf('Storage reference %s is not an opaque reference token.', json_encode($value, JSON_INVALID_UTF8_SUBSTITUTE)));
        }
    }

    public function toString(): string
    {
        return $this->value;
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
