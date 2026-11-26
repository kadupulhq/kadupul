<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\IdentityAccess\Domain;

/**
 * 64-character lowercase hex token binding active sessions and v1 remembered
 * cookies to an account's current credential epoch. Stored in settings_user
 * under auth_credential_generation as "<sha256(password)>:<generation>".
 */
final readonly class CredentialGeneration
{
    private const string HEX_PATTERN = '/\A[a-f0-9]{64}\z/D';
    private const string MAPPING_PATTERN = '/\A([a-f0-9]{64}):([a-f0-9]{64})\z/D';

    private function __construct(
        #[\SensitiveParameter]
        private string $value,
    ) {}

    public static function fromHex(#[\SensitiveParameter] string $hex): self
    {
        if (preg_match(self::HEX_PATTERN, $hex) !== 1) {
            throw new \InvalidArgumentException('Credential generation must be 64 lowercase hexadecimal characters.');
        }

        return new self($hex);
    }

    public static function fromStoredPassword(#[\SensitiveParameter] string $passwordHash): self
    {
        return new self(self::passwordFingerprint($passwordHash));
    }

    public static function generate(): self
    {
        return new self(bin2hex(random_bytes(32)));
    }

    public static function passwordFingerprint(#[\SensitiveParameter] string $passwordHash): string
    {
        return hash('sha256', $passwordHash);
    }

    /**
     * Resolves the effective generation from an optional stored mapping row.
     * Falls back to the password fingerprint when no matching mapping exists.
     */
    public static function resolveFromMapping(
        #[\SensitiveParameter]
        string $passwordHash,
        #[\SensitiveParameter]
        ?string $storedMapping,
    ): self {
        $fingerprint = self::passwordFingerprint($passwordHash);
        if (
            $storedMapping !== null
            && preg_match(self::MAPPING_PATTERN, $storedMapping, $parts) === 1
            && hash_equals($fingerprint, $parts[1])
        ) {
            return new self($parts[2]);
        }

        return new self($fingerprint);
    }

    public function toHex(): string
    {
        return $this->value;
    }

    public function mappingFor(#[\SensitiveParameter] string $passwordHash): string
    {
        return self::passwordFingerprint($passwordHash) . ':' . $this->value;
    }

    public function matches(#[\SensitiveParameter] string|self $other): bool
    {
        $candidate = $other instanceof self ? $other->value : $other;
        if (preg_match(self::HEX_PATTERN, $candidate) !== 1) {
            return false;
        }

        return hash_equals($this->value, $candidate);
    }
}
