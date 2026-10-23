<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\ModelRegistry\Domain;

final class Checksum
{
    private function __construct(private readonly string $value) {}

    public static function fromHex(string $value): self
    {
        if (preg_match('/\A[0-9a-f]{64}\z/', $value) !== 1) {
            throw new InvalidModelDefinition('A model checksum must be 64 lowercase hexadecimal characters (SHA-256).');
        }

        return new self($value);
    }

    public function value(): string
    {
        return $this->value;
    }

    public function equals(self $other): bool
    {
        return hash_equals($this->value, $other->value);
    }
}
