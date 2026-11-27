<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\IdentityAccess\Domain;

/**
 * Remembered login token matching the v1 HMAC-bound wire format in lib/auth.php
 * and ADR 0010 Decision 4: "v1.<64 hex>.<hmac_sha256(64 hex, generation)>".
 * Legacy unversioned tokens remain parseable for backward-compatible rotation.
 */
final readonly class RememberedToken
{
    private const string VERSIONED_PATTERN = '/\Av1\.([a-f0-9]{64})\.([a-f0-9]{64})\z/D';
    private const int MAX_TOKEN_LENGTH = 512;

    private function __construct(
        #[\SensitiveParameter]
        private string $raw,
    ) {}

    public static function issue(CredentialGeneration $generation, #[\SensitiveParameter] ?string $nonce = null): self
    {
        $secret = $nonce ?? bin2hex(random_bytes(32));
        if (preg_match('/\A[a-f0-9]{64}\z/D', $secret) !== 1) {
            throw new \InvalidArgumentException('Remembered token nonce must be 64 lowercase hexadecimal characters.');
        }

        $mac = hash_hmac('sha256', $secret, $generation->toHex());

        return new self('v1.' . $secret . '.' . $mac);
    }

    public static function fromRaw(#[\SensitiveParameter] string $raw): self
    {
        if ($raw === '' || strlen($raw) > self::MAX_TOKEN_LENGTH || str_contains($raw, ',')) {
            throw new \InvalidArgumentException('Invalid remembered token.');
        }

        return new self($raw);
    }

    /**
     * Parses a cacti_remembers cookie value in either "<id>,<token>" (realm 0)
     * or "<id>,<realm>,<token>" format.
     *
     * @return ?array{identity: string, realm: int, token: self}
     */
    public static function parseCookie(#[\SensitiveParameter] string $cookie): ?array
    {
        $parts = explode(',', $cookie);
        if (count($parts) === 2) {
            [$identity, $rawToken] = $parts;
            $realm = 0;
        } elseif (count($parts) === 3 && preg_match('/\A[0-9]+\z/D', $parts[1]) === 1) {
            [$identity, $realmRaw, $rawToken] = $parts;
            $realm = (int) $realmRaw;
        } else {
            return null;
        }

        if ($identity === '' || $rawToken === '' || strlen($rawToken) > self::MAX_TOKEN_LENGTH) {
            return null;
        }

        return [
            'identity' => $identity,
            'realm' => $realm,
            'token' => new self($rawToken),
        ];
    }

    public function raw(): string
    {
        return $this->raw;
    }

    public function isVersioned(): bool
    {
        return str_starts_with($this->raw, 'v1.');
    }

    /**
     * Verifies a v1 token against the active credential generation in constant time.
     * Unversioned legacy tokens pass unless $requireVersioned is true.
     */
    public function isValidForGeneration(CredentialGeneration $generation, bool $requireVersioned = false): bool
    {
        if (!$this->isVersioned()) {
            return !$requireVersioned;
        }

        if (preg_match(self::VERSIONED_PATTERN, $this->raw, $parts) !== 1) {
            return false;
        }

        $expected = hash_hmac('sha256', $parts[1], $generation->toHex());

        return hash_equals($expected, $parts[2]);
    }

    /** SHA-512 hex digest stored in user_auth_cache.token. */
    public function storageHash(): string
    {
        return hash('sha512', $this->raw, false);
    }

    public function formatCookie(int $userId, int $realm): string
    {
        if ($userId < 1 || $realm < 0) {
            throw new \InvalidArgumentException('Cookie user id must be positive and realm non-negative.');
        }

        return $userId . ',' . $realm . ',' . $this->raw;
    }
}
