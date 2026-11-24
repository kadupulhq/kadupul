<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace Kadupul\IdentityAccess\Application\Command;

use Kadupul\IdentityAccess\Application\Port\AuthenticationTransitionAccounts;
use Kadupul\IdentityAccess\Application\Port\AuthenticationTransitionSession;

final readonly class TransitionAuthenticationSession
{
    public function __construct(
        private AuthenticationTransitionAccounts $accounts,
        private AuthenticationTransitionSession $session,
    ) {}

    public function __invoke(int $userId, string $reason): bool
    {
        $restored = $this->session->takeCookieCredential();
        $account = $this->accounts->find($userId);
        if ($account === null || ($account['enabled'] !== 'on' && $userId !== $this->accounts->guestId()) || $account['locked'] === 'on') {
            $this->session->logBlocked($userId, $reason);
            return false;
        }

        if ($reason === 'cookie_restore' && (!is_array($restored) || ($restored['user_id'] ?? null) !== $userId
            || !is_string($restored['generation'] ?? null)
            || !hash_equals($this->accounts->credentialGeneration($userId, $account['password']), $restored['generation']))) {
            return false;
        }

        if ($this->session->isActive()) {
            if (!$this->session->regenerate()) {
                $this->session->logTransitionFailure($userId, $reason);
                return false;
            }
        }
        $this->session->clearPermissionCaches();
        if ($reason === 'cookie_restore') {
            // Keep the snapshot that admitted the token if revocation races this transition.
            $this->session->bindRestoredCredential($restored['generation']);
        } else {
            $this->session->bindCredential($userId);
        }
        $this->session->logCompleted($userId, $reason);
        return true;
    }
}
