<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\ModelRegistry\Domain;

/**
 * SHA-256 digest of a model's content, used to detect a changed model.
 */
final class Checksum
{
    /**
     * Keeps the validated digest.
     */
    private function __construct(private readonly string $value) {}

    /**
     * Builds a checksum from 64 lowercase hexadecimal characters.
     *
     * @throws InvalidModelDefinition when the value is not in that form
     */
    public static function fromHex(string $value): self
    {
        if (preg_match('/\A[0-9a-f]{64}\z/', $value) !== 1) {
            throw new InvalidModelDefinition('A model checksum must be 64 lowercase hexadecimal characters (SHA-256).');
        }

        return new self($value);
    }

    /**
     * Returns the lowercase hexadecimal digest.
     */
    public function value(): string
    {
        return $this->value;
    }

    /**
     * Compares digests in constant time.
     */
    public function equals(self $other): bool
    {
        return hash_equals($this->value, $other->value);
    }
}
