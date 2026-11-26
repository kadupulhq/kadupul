<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\IdentityAccess\Application\Port;

/**
 * Immutable result of an LDAP / Active Directory authentication attempt.
 */
final readonly class DirectoryAuthenticationResult
{
    private function __construct(
        public DirectoryAuthenticationStatus $status,
        public ?string $resolvedDn = null,
        public ?string $fullName = null,
        public ?string $emailAddress = null,
    ) {}

    public static function authenticated(
        string $resolvedDn,
        ?string $fullName = null,
        ?string $emailAddress = null,
    ): self {
        return new self(
            DirectoryAuthenticationStatus::Authenticated,
            $resolvedDn,
            $fullName,
            $emailAddress,
        );
    }

    public static function invalidCredentials(): self
    {
        return new self(DirectoryAuthenticationStatus::InvalidCredentials);
    }

    public static function groupMembershipDenied(): self
    {
        return new self(DirectoryAuthenticationStatus::GroupMembershipDenied);
    }

    public static function configurationError(): self
    {
        return new self(DirectoryAuthenticationStatus::ConfigurationError);
    }

    public static function unavailable(): self
    {
        return new self(DirectoryAuthenticationStatus::Unavailable);
    }

    public function isAuthenticated(): bool
    {
        return $this->status === DirectoryAuthenticationStatus::Authenticated;
    }
}
