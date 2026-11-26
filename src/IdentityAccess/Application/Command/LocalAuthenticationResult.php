<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\IdentityAccess\Application\Command;

use Kadupul\IdentityAccess\Domain\CredentialGeneration;
use Kadupul\IdentityAccess\Domain\Event\UserAuthenticated;
use Kadupul\IdentityAccess\Domain\User;

/**
 * Immutable outcome of a local credential authentication attempt.
 */
final readonly class LocalAuthenticationResult
{
    public const string STATUS_AUTHENTICATED = 'authenticated';
    public const string STATUS_PASSWORD_CHANGE_REQUIRED = 'password_change_required';
    public const string STATUS_RATE_LIMITED = 'rate_limited';
    public const string STATUS_LOCKED = 'locked';
    public const string STATUS_COMPLEXITY_DENIED = 'complexity_denied';
    public const string STATUS_INVALID_CREDENTIALS = 'invalid_credentials';
    public const string STATUS_REHASH_CONFLICT = 'rehash_conflict';

    private function __construct(
        public string $status,
        public ?User $user = null,
        public ?CredentialGeneration $generation = null,
        public ?UserAuthenticated $event = null,
        public int $retryAfterSeconds = 0,
    ) {}

    public static function authenticated(User $user, CredentialGeneration $generation, UserAuthenticated $event): self
    {
        return new self(self::STATUS_AUTHENTICATED, $user, $generation, $event);
    }

    public static function passwordChangeRequired(User $user, CredentialGeneration $generation, UserAuthenticated $event): self
    {
        return new self(self::STATUS_PASSWORD_CHANGE_REQUIRED, $user, $generation, $event);
    }

    public static function rateLimited(int $retryAfterSeconds): self
    {
        return new self(self::STATUS_RATE_LIMITED, null, null, null, $retryAfterSeconds);
    }

    public static function locked(): self
    {
        return new self(self::STATUS_LOCKED);
    }

    public static function complexityDenied(): self
    {
        return new self(self::STATUS_COMPLEXITY_DENIED);
    }

    public static function invalidCredentials(): self
    {
        return new self(self::STATUS_INVALID_CREDENTIALS);
    }

    public static function rehashConflict(): self
    {
        return new self(self::STATUS_REHASH_CONFLICT);
    }

    public function allowsSessionEstablishment(): bool
    {
        return $this->status === self::STATUS_AUTHENTICATED || $this->status === self::STATUS_PASSWORD_CHANGE_REQUIRED;
    }
}
