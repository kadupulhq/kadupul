<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\IdentityAccess\Domain;

use Kadupul\IdentityAccess\Domain\Event\CredentialsRevoked;
use Kadupul\IdentityAccess\Domain\Event\RealmGrantChanged;
use Kadupul\IdentityAccess\Domain\Event\UserAuthenticated;
use Kadupul\IdentityAccess\Domain\Policy\LockoutPolicy;

/**
 * User account aggregate root modeling identity, lockout state, direct realm
 * grants, and credential generation lifecycle.
 */
final class User
{
    /** @var list<int> */
    public private(set) array $directRealms;

    /** @var list<UserAuthenticated|CredentialsRevoked|RealmGrantChanged> */
    private array $events = [];

    /**
     * @param list<int> $directRealms
     */
    public function __construct(
        public readonly int $id,
        public readonly string $username,
        public readonly int $accountRealm,
        public private(set) CredentialGeneration $credentialGeneration,
        public private(set) bool $enabled = true,
        public private(set) bool $locked = false,
        public private(set) bool $mustChangePassword = false,
        public private(set) bool $passwordChangeAllowed = true,
        public private(set) int $failedAttempts = 0,
        public private(set) int $lastFailureAt = 0,
        public private(set) string $fullName = '',
        public private(set) string $emailAddress = '',
        array $directRealms = [],
    ) {
        if ($id < 1) {
            throw new \InvalidArgumentException('User id must be positive.');
        }
        if (trim($username) === '') {
            throw new \InvalidArgumentException('Username must not be empty.');
        }
        if ($accountRealm < 0) {
            throw new \InvalidArgumentException('Account realm must be non-negative.');
        }
        if ($failedAttempts < 0 || $lastFailureAt < 0) {
            throw new \InvalidArgumentException('Lockout counters must be non-negative.');
        }

        $normalized = [];
        foreach ($directRealms as $realmId) {
            if ($realmId > 0) {
                $normalized[$realmId] = $realmId;
            }
        }
        sort($normalized, SORT_NUMERIC);
        $this->directRealms = $normalized;
    }

    public function isEligibleForLogin(bool $isGuestAccount = false): bool
    {
        return $this->enabled && !$this->locked && !$isGuestAccount;
    }

    /** Matches local forced-change eligibility in auth_login.php and CliConsoleAccess. */
    public function requiresForcedPasswordChange(): bool
    {
        return $this->accountRealm === 0 && $this->mustChangePassword && $this->passwordChangeAllowed;
    }

    public function hasDirectRealm(AuthRealm|int $realm): bool
    {
        $realmId = $realm instanceof AuthRealm ? $realm->value : $realm;

        return in_array($realmId, $this->directRealms, true);
    }

    public function grantRealm(AuthRealm|int $realm, \DateTimeImmutable $occurredAt): void
    {
        $realmId = $realm instanceof AuthRealm ? $realm->value : $realm;
        if ($realmId < 1) {
            throw new \InvalidArgumentException('Realm id must be positive.');
        }
        if (in_array($realmId, $this->directRealms, true)) {
            return;
        }

        $realms = $this->directRealms;
        $realms[] = $realmId;
        sort($realms, SORT_NUMERIC);
        $this->directRealms = $realms;
        $this->events[] = new RealmGrantChanged($this->id, false, $realmId, true, $occurredAt);
    }

    public function revokeRealm(AuthRealm|int $realm, \DateTimeImmutable $occurredAt): void
    {
        $realmId = $realm instanceof AuthRealm ? $realm->value : $realm;
        if (!in_array($realmId, $this->directRealms, true)) {
            return;
        }

        $this->directRealms = array_values(array_filter(
            $this->directRealms,
            static fn(int $id): bool => $id !== $realmId,
        ));
        $this->events[] = new RealmGrantChanged($this->id, false, $realmId, false, $occurredAt);
    }

    public function recordAuthentication(AuthMethod $method, string $clientAddress, \DateTimeImmutable $occurredAt): void
    {
        $this->failedAttempts = 0;
        $this->lastFailureAt = 0;
        $this->events[] = new UserAuthenticated(
            $this->id,
            $this->username,
            $this->accountRealm,
            $method,
            $clientAddress,
            $occurredAt,
        );
    }

    public function revokeAllCredentials(
        CredentialGeneration $nextGeneration,
        string $reason,
        \DateTimeImmutable $occurredAt,
    ): void {
        if ($this->credentialGeneration->matches($nextGeneration)) {
            throw new \InvalidArgumentException('Revocation requires a distinct credential generation.');
        }
        if (trim($reason) === '') {
            throw new \InvalidArgumentException('Revocation reason must not be empty.');
        }

        $this->credentialGeneration = $nextGeneration;
        $this->events[] = new CredentialsRevoked($this->id, $nextGeneration, $reason, $occurredAt);
    }

    /** Returns true when this failure caused or confirmed an account lockout. */
    public function recordFailedLogin(LockoutPolicy $policy, int $timestamp): bool
    {
        if (!$policy->isEnabled() || !$this->enabled) {
            return false;
        }

        $this->failedAttempts++;
        $this->lastFailureAt = $timestamp;
        if ($policy->shouldLock($this->failedAttempts)) {
            $this->locked = true;
        }

        return $this->locked;
    }

    /** Returns true when an expired lockout was cleared. */
    public function recoverLockout(LockoutPolicy $policy, int $nowTimestamp): bool
    {
        if (!$this->enabled || !$policy->hasLockoutExpired($this->lastFailureAt, $nowTimestamp)) {
            return false;
        }

        $this->locked = false;
        $this->failedAttempts = 0;
        $this->lastFailureAt = 0;

        return true;
    }

    public function requirePasswordChange(): void
    {
        $this->mustChangePassword = true;
    }

    public function completePasswordChange(
        CredentialGeneration $nextGeneration,
        \DateTimeImmutable $occurredAt,
    ): void {
        $this->mustChangePassword = false;
        $this->revokeAllCredentials($nextGeneration, 'password_changed', $occurredAt);
    }

    public function disable(): void
    {
        $this->enabled = false;
    }

    public function enable(): void
    {
        $this->enabled = true;
    }

    /** @return list<UserAuthenticated|CredentialsRevoked|RealmGrantChanged> */
    public function releaseEvents(): array
    {
        $events = $this->events;
        $this->events = [];

        return $events;
    }
}
