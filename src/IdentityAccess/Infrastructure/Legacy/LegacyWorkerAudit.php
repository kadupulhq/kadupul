<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\IdentityAccess\Infrastructure\Legacy;

use Kadupul\IdentityAccess\Contract\AuditEvent;
use Kadupul\IdentityAccess\Contract\AuditTrail;

/**
 * The audit fields of one procedural write worker. They stay mutable because
 * the worker learns the actor, target and decision as the command advances.
 */
final class LegacyWorkerAudit
{
    public string $correlationId;
    public ?int $actorId = null;
    public string $decision = AuditEvent::DENIED;
    public string $outcome = AuditEvent::DENIED;

    public function __construct(
        private readonly AuditTrail $trail,
        private readonly string $action,
        private readonly string $targetType,
        public string $targetId,
    ) {
        // Commands rejected before correlation are still recorded, under an
        // identifier the parent never supplied.
        $this->correlationId = bin2hex(random_bytes(16));
    }

    public function correlate(mixed $candidate): void
    {
        if (!is_string($candidate) || !preg_match('/^[a-f0-9]{32}$/D', $candidate)) {
            throw new \InvalidArgumentException('Invalid command');
        }
        $this->correlationId = $candidate;
    }

    /**
     * Records only after the transaction resolves, so the event never
     * describes a write that a later rollback could still undo.
     *
     * @param (callable(): mixed)|null $rollback
     */
    public function recordAfter(?callable $rollback): void
    {
        if ($rollback !== null) {
            try {
                $rollback();
            } catch (\Throwable) {
                // Audit the unresolved operation even when legacy rollback reports an error.
            }
        }
        try {
            $this->trail->record(new AuditEvent(
                $this->correlationId,
                $this->actorId,
                $this->action,
                $this->targetType,
                $this->targetId,
                $this->decision,
                $this->outcome,
            ));
        } catch (\Throwable) {
            // The transitional sink must not replace the stable worker result.
        }
    }
}
