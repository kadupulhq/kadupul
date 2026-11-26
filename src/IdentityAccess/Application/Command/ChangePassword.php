<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\IdentityAccess\Application\Command;

use Kadupul\IdentityAccess\Application\Port\Clock;
use Kadupul\IdentityAccess\Application\Port\PasswordCredentialStore;
use Kadupul\IdentityAccess\Application\Port\PasswordVerifier;
use Kadupul\IdentityAccess\Contract\AuditEvent;
use Kadupul\IdentityAccess\Contract\AuditTrail;
use Kadupul\IdentityAccess\Domain\CredentialGeneration;
use Kadupul\IdentityAccess\Domain\Event\CredentialsRevoked;
use Kadupul\IdentityAccess\Domain\PasswordComplexity;

/**
 * Validates and persists a local password change, rotating the account's
 * credential generation so existing sessions and remembered cookies are revoked.
 */
final readonly class ChangePassword
{
    public function __construct(
        private PasswordCredentialStore $store,
        private PasswordVerifier $verifier,
        private PasswordComplexity $complexity,
        private AuditTrail $audit,
        private Clock $clock,
    ) {}

    public function __invoke(
        int $userId,
        #[\SensitiveParameter]
        string $currentPassword,
        #[\SensitiveParameter]
        string $newPassword,
        bool $requireCurrentPassword = true,
    ): PasswordChangeResult {
        $account = $this->store->findAccountById($userId);
        if ($account === null || !$account->user->isEligibleForLogin($account->isGuestAccount)) {
            return PasswordChangeResult::accountIneligible();
        }
        if ($account->user->accountRealm !== 0 || !$account->user->passwordChangeAllowed) {
            return PasswordChangeResult::notAllowed();
        }

        if ($requireCurrentPassword && !$this->verifier->verify($account->storedPasswordHash(), $currentPassword)) {
            return PasswordChangeResult::currentPasswordInvalid();
        }

        if ($newPassword === '') {
            return PasswordChangeResult::complexityViolation(['min_length']);
        }

        $violations = $this->store->complexityPolicy()->violations($newPassword, $this->complexity);
        if ($violations !== []) {
            return PasswordChangeResult::complexityViolation($violations);
        }

        if ($this->verifier->verify($account->storedPasswordHash(), $newPassword)) {
            return PasswordChangeResult::passwordReused();
        }

        $newHash = $this->verifier->hash($newPassword);
        $nextGeneration = CredentialGeneration::generate();
        while ($account->generation->matches($nextGeneration)) {
            $nextGeneration = CredentialGeneration::generate();
        }

        $changed = $this->store->changePasswordAndRotateGeneration(
            $userId,
            $account->storedPasswordHash(),
            $newHash,
            $nextGeneration,
        );
        $now = $this->clock->now();
        $this->audit->record(new AuditEvent(
            bin2hex(random_bytes(16)),
            $userId,
            'authentication.change_password',
            'account',
            (string) $userId,
            AuditEvent::ALLOWED,
            $changed ? AuditEvent::SUCCEEDED : AuditEvent::FAILED,
            $now->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\\TH:i:s\\Z'),
        ));

        if (!$changed) {
            return PasswordChangeResult::conflict();
        }

        return PasswordChangeResult::changed(
            $nextGeneration,
            new CredentialsRevoked($userId, $nextGeneration, 'password_changed', $now),
        );
    }
}
