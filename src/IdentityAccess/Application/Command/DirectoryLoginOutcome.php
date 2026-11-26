<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\IdentityAccess\Application\Command;

use Kadupul\IdentityAccess\Application\Port\DirectoryAuthenticationResult;
use Kadupul\IdentityAccess\Application\Port\DirectoryAuthenticationStatus;

/**
 * Immutable outcome of a directory login attempt.
 */
final readonly class DirectoryLoginOutcome
{
    public const string STATUS_AUTHENTICATED = 'authenticated';
    public const string STATUS_RATE_LIMITED = 'rate_limited';
    public const string STATUS_DOMAIN_UNAVAILABLE = 'domain_unavailable';
    public const string STATUS_INVALID_CREDENTIALS = 'invalid_credentials';
    public const string STATUS_GROUP_DENIED = 'group_denied';
    public const string STATUS_DIRECTORY_UNAVAILABLE = 'directory_unavailable';

    private function __construct(
        public string $status,
        public int $accountRealm,
        public int $retryAfterSeconds = 0,
        public ?string $resolvedDn = null,
        public ?string $fullName = null,
        public ?string $emailAddress = null,
    ) {}

    public static function authenticated(int $accountRealm, DirectoryAuthenticationResult $result): self
    {
        return new self(
            self::STATUS_AUTHENTICATED,
            $accountRealm,
            0,
            $result->resolvedDn,
            $result->fullName,
            $result->emailAddress,
        );
    }

    public static function rateLimited(int $accountRealm, int $retryAfterSeconds): self
    {
        return new self(self::STATUS_RATE_LIMITED, $accountRealm, $retryAfterSeconds);
    }

    public static function domainUnavailable(int $accountRealm): self
    {
        return new self(self::STATUS_DOMAIN_UNAVAILABLE, $accountRealm);
    }

    public static function fromFailure(int $accountRealm, DirectoryAuthenticationStatus $status): self
    {
        $mapped = match ($status) {
            DirectoryAuthenticationStatus::GroupMembershipDenied => self::STATUS_GROUP_DENIED,
            DirectoryAuthenticationStatus::Unavailable, DirectoryAuthenticationStatus::ConfigurationError => self::STATUS_DIRECTORY_UNAVAILABLE,
            default => self::STATUS_INVALID_CREDENTIALS,
        };

        return new self($mapped, $accountRealm);
    }

    public function isAuthenticated(): bool
    {
        return $this->status === self::STATUS_AUTHENTICATED;
    }

    public function isRateLimited(): bool
    {
        return $this->status === self::STATUS_RATE_LIMITED;
    }
}
