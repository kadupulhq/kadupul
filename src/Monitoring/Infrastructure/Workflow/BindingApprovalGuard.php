<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Monitoring\Infrastructure\Workflow;

use Kadupul\IdentityAccess\Contract\AuditEvent;
use Kadupul\IdentityAccess\Contract\AuditTrail;
use Kadupul\IdentityAccess\Contract\BindingApprovalPermission;
use Kadupul\IdentityAccess\Contract\ConsoleAccess;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Workflow\Event\GuardEvent;

/**
 * Decides the `approve` transition of a binding. The actor is the one
 * IdentityAccess resolves for this request, never the id the caller passed, and
 * anything that cannot be established (no actor, a mismatch, a failed lookup,
 * a failed audit write) blocks the transition. Every decision is audited. The
 * event records the authorization decision, not the later write; the action
 * name says so.
 */
final readonly class BindingApprovalGuard
{
    public const string ACTION = 'monitoring.binding.authorize';

    public function __construct(
        private ConsoleAccess $access,
        private BindingApprovalPermission $permission,
        private AuditTrail $audit,
    ) {}

    #[AsEventListener(event: 'workflow.metric_binding.guard.approve')]
    public function __invoke(GuardEvent $event): void
    {
        $subject = $event->getSubject();
        if (!$subject instanceof BindingSubject) {
            $event->setBlocked(true, 'Only a binding subject can be approved.');

            return;
        }

        $actorId = null;
        try {
            $actor = $this->access->consoleActor();
            $actorId = $actor?->id;
            $reason = match (true) {
                $actor === null => 'No signed-in console actor.',
                (string) $actor->id !== $subject->actorId => 'The requested actor is not the signed-in actor.',
                !$this->permission->mayApproveBindings($actor) => 'The actor may not approve bindings.',
                default => null,
            };
        } catch (\Throwable) {
            $reason = 'The actor could not be established.';
        }

        $allowed = $reason === null;
        try {
            $this->audit->record(new AuditEvent(
                bin2hex(random_bytes(16)),
                $actorId,
                self::ACTION,
                'metric_binding',
                $subject->key->bindingId(),
                $allowed ? AuditEvent::ALLOWED : AuditEvent::DENIED,
                $allowed ? AuditEvent::SUCCEEDED : AuditEvent::DENIED,
            ));
        } catch (\Throwable) {
            $allowed = false;
            $reason ??= 'The decision could not be audited.';
        }

        if (!$allowed) {
            $event->setBlocked(true, $reason);
        }
    }
}
