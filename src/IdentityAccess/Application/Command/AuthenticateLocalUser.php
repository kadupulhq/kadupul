<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\IdentityAccess\Application\Command;

use Kadupul\IdentityAccess\Application\Port\Clock;
use Kadupul\IdentityAccess\Application\Port\LoginRateLimiter;
use Kadupul\IdentityAccess\Application\Port\PasswordCredentialStore;
use Kadupul\IdentityAccess\Application\Port\PasswordVerifier;
use Kadupul\IdentityAccess\Domain\AuthMethod;
use Kadupul\IdentityAccess\Domain\Event\UserAuthenticated;
use Kadupul\IdentityAccess\Domain\PasswordComplexity;

/**
 * Pure application use case for local username/password authentication
 * (ADR 0010 Stage c): enforces additive rate limiting, lockout policy,
 * constant-time unknown-user padding, session-preserving password rehash,
 * and complexity-driven forced password change.
 */
final readonly class AuthenticateLocalUser
{
    private const int LOCAL_REALM = 0;

    public function __construct(
        private PasswordCredentialStore $store,
        private PasswordVerifier $verifier,
        private PasswordComplexity $complexity,
        private LoginRateLimiter $rateLimiter,
        private Clock $clock,
    ) {}

    public function __invoke(
        string $username,
        #[\SensitiveParameter]
        string $password,
        string $clientAddress,
    ): LocalAuthenticationResult {
        $limit = $this->rateLimiter->consume($clientAddress, $username, self::LOCAL_REALM);
        if (!$limit->allowed) {
            return LocalAuthenticationResult::rateLimited($limit->retryAfterSeconds);
        }

        if ($username === '' || $password === '') {
            $this->verifier->padUnknownUserTiming();

            return LocalAuthenticationResult::invalidCredentials();
        }

        $account = $this->store->findLocalAccountByUsername($username);
        if ($account === null || !$account->user->enabled || $account->isGuestAccount) {
            $this->verifier->padUnknownUserTiming();

            return LocalAuthenticationResult::invalidCredentials();
        }

        $lockoutPolicy = $this->store->lockoutPolicy();
        $now = $this->clock->now();
        if ($lockoutPolicy->isEnabled() && $account->user->locked) {
            if (!$account->user->recoverLockout($lockoutPolicy, $now->getTimestamp())) {
                $this->verifier->padUnknownUserTiming();

                return LocalAuthenticationResult::locked();
            }
        }

        $storedHash = $account->storedPasswordHash();
        if (!$this->verifier->verify($storedHash, $password)) {
            return LocalAuthenticationResult::invalidCredentials();
        }

        if ($this->verifier->needsRehash($storedHash)) {
            $rehashed = $this->verifier->hash($password);
            if (!$this->store->updatePasswordPreservingSessions($account->user->id, $storedHash, $rehashed)) {
                return LocalAuthenticationResult::rehashConflict();
            }
        }

        $complexityPolicy = $this->store->complexityPolicy();
        $requiresChange = $account->user->requiresForcedPasswordChange();
        if (!$complexityPolicy->isSatisfiedBy($password, $this->complexity)) {
            if (!$account->user->passwordChangeAllowed) {
                return LocalAuthenticationResult::complexityDenied();
            }
            $this->store->requirePasswordChange($account->user->id);
            $account->user->requirePasswordChange();
            $requiresChange = true;
        }

        $this->store->recordLastLogin($account->user->id);
        $this->rateLimiter->resetAccount($username, self::LOCAL_REALM);
        $account->user->recordAuthentication(AuthMethod::Builtin, $clientAddress, $now);

        $event = new UserAuthenticated(
            $account->user->id,
            $account->user->username,
            $account->user->accountRealm,
            AuthMethod::Builtin,
            $clientAddress,
            $now,
        );

        return $requiresChange
            ? LocalAuthenticationResult::passwordChangeRequired($account->user, $account->generation, $event)
            : LocalAuthenticationResult::authenticated($account->user, $account->generation, $event);
    }
}
