<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\IdentityAccess\Application\Command;

use Kadupul\IdentityAccess\Application\Port\Clock;
use Kadupul\IdentityAccess\Application\Port\SessionCredentialRevoker;
use Kadupul\IdentityAccess\Contract\AuditEvent;
use Kadupul\IdentityAccess\Contract\AuditTrail;
use Kadupul\IdentityAccess\Domain\CredentialGeneration;
use Kadupul\IdentityAccess\Domain\Event\CredentialsRevoked;

/**
 * Atomically rotates an account's credential generation and purges persisted
 * sessions and remembered tokens (ADR 0010 Stage e / Logout Everywhere).
 */
final readonly class RevokeAllUserSessions
{
    public function __construct(
        private SessionCredentialRevoker $revoker,
        private AuditTrail $audit,
        private Clock $clock,
    ) {}

    public function __invoke(
        int $userId,
        CredentialGeneration $currentGeneration,
        string $reason = 'logout_everywhere',
    ): SessionRevocationResult {
        if ($userId < 1 || trim($reason) === '') {
            return SessionRevocationResult::rejected();
        }

        $nextGeneration = CredentialGeneration::generate();
        while ($currentGeneration->matches($nextGeneration)) {
            $nextGeneration = CredentialGeneration::generate();
        }

        $revoked = $this->revoker->revokeAll($userId, $currentGeneration, $nextGeneration);
        $now = $this->clock->now();
        $this->audit->record(new AuditEvent(
            bin2hex(random_bytes(16)),
            $userId,
            'authentication.revoke_sessions',
            'account',
            (string) $userId,
            $revoked ? AuditEvent::ALLOWED : AuditEvent::DENIED,
            $revoked ? AuditEvent::SUCCEEDED : AuditEvent::DENIED,
            $now->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\\TH:i:s\\Z'),
        ));

        if (!$revoked) {
            return SessionRevocationResult::rejected();
        }

        return SessionRevocationResult::revoked(
            $nextGeneration,
            new CredentialsRevoked($userId, $nextGeneration, $reason, $now),
        );
    }
}
