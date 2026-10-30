<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests\Monitoring;

use Kadupul\IdentityAccess\Contract\Actor;
use Kadupul\IdentityAccess\Contract\AuditEvent;
use Kadupul\IdentityAccess\Contract\BindingApprovalPermission;
use Kadupul\IdentityAccess\Contract\ConsoleAccess;
use Kadupul\Monitoring\Infrastructure\Workflow\BindingApprovalGuard;
use Kadupul\Monitoring\Infrastructure\Workflow\CapabilitySubject;
use Kadupul\Monitoring\Infrastructure\Workflow\StatusMarkingStore;
use Kadupul\Monitoring\Infrastructure\Workflow\WorkflowBindingApprovalAuthorizer;
use Kadupul\Tests\Monitoring\Fakes\RecordingAuditTrail;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Workflow\Definition;
use Symfony\Component\Workflow\Event\GuardEvent;
use Symfony\Component\Workflow\Marking;
use Symfony\Component\Workflow\StateMachine;
use Symfony\Component\Workflow\Transition;

/**
 * Drives the real guard through a real state machine; only the IdentityAccess
 * ports and the audit sink are fakes.
 */
final class BindingApprovalGuardTest extends TestCase
{
    private RecordingAuditTrail $audit;

    protected function setUp(): void
    {
        $this->audit = new RecordingAuditTrail();
    }

    public function testASignedInActorWithThePermissionIsAllowedAndAudited(): void
    {
        $authorizer = $this->authorizer(new Actor(7, 'operator'), true);

        self::assertTrue($authorizer->isAuthorized('7', BindingFixture::key()));
        $this->assertOneDecision(AuditEvent::ALLOWED, AuditEvent::SUCCEEDED, 7);
    }

    public function testAnActorWithoutThePermissionIsDeniedAndAudited(): void
    {
        $authorizer = $this->authorizer(new Actor(7, 'operator'), false);

        self::assertFalse($authorizer->isAuthorized('7', BindingFixture::key()));
        $this->assertOneDecision(AuditEvent::DENIED, AuditEvent::DENIED, 7);
    }

    public function testNoSignedInActorIsDeniedEvenWhenTheCallerNamesOne(): void
    {
        $authorizer = $this->authorizer(null, true);

        self::assertFalse($authorizer->isAuthorized('7', BindingFixture::key()));
        $this->assertOneDecision(AuditEvent::DENIED, AuditEvent::DENIED, null);
    }

    public function testAClaimedActorThatIsNotTheSignedInActorIsDenied(): void
    {
        $authorizer = $this->authorizer(new Actor(7, 'operator'), true);

        self::assertFalse($authorizer->isAuthorized('8', BindingFixture::key()));
        $this->assertOneDecision(AuditEvent::DENIED, AuditEvent::DENIED, 7);
    }

    public function testAFailedActorLookupIsDenied(): void
    {
        $access = new class implements ConsoleAccess {
            public function consoleActor(): ?Actor
            {
                throw new \RuntimeException('database gone');
            }

            public function canManageDevices(Actor $actor): bool
            {
                return true;
            }
        };

        self::assertFalse($this->build($access, self::permission(true))->isAuthorized('7', BindingFixture::key()));
        $this->assertOneDecision(AuditEvent::DENIED, AuditEvent::DENIED, null);
    }

    public function testAFailedPermissionLookupIsDenied(): void
    {
        $permission = new class implements BindingApprovalPermission {
            public function mayApproveBindings(Actor $actor): bool
            {
                throw new \RuntimeException('database gone');
            }
        };

        self::assertFalse($this->build(self::access(new Actor(7, 'operator')), $permission)->isAuthorized('7', BindingFixture::key()));
        $this->assertOneDecision(AuditEvent::DENIED, AuditEvent::DENIED, 7);
    }

    public function testAnAllowedDecisionThatCannotBeAuditedIsDenied(): void
    {
        $this->audit->failing = true;

        self::assertFalse($this->authorizer(new Actor(7, 'operator'), true)->isAuthorized('7', BindingFixture::key()));
        self::assertSame([], $this->audit->events);
    }

    public function testADeniedDecisionStaysDeniedWhenTheAuditSinkFails(): void
    {
        $this->audit->failing = true;

        self::assertFalse($this->authorizer(new Actor(7, 'operator'), false)->isAuthorized('7', BindingFixture::key()));
    }

    public function testEveryDecisionNamesTheBindingAndAUniqueCorrelationId(): void
    {
        $authorizer = $this->authorizer(new Actor(7, 'operator'), true);
        $key = BindingFixture::key();
        $authorizer->isAuthorized('7', $key);
        $authorizer->isAuthorized('8', $key);

        self::assertCount(2, $this->audit->events);
        self::assertSame($key->bindingId(), $this->audit->events[0]->targetId);
        self::assertSame('metric_binding', $this->audit->events[0]->targetType);
        self::assertSame(BindingApprovalGuard::ACTION, $this->audit->events[0]->action);
        self::assertNotSame($this->audit->events[0]->correlationId, $this->audit->events[1]->correlationId);
    }

    public function testASubjectThatIsNotABindingIsBlockedWithoutAnAuditRecord(): void
    {
        $subject = new CapabilitySubject();
        $event = new GuardEvent(
            $subject,
            new Marking(['advertised' => 1]),
            new Transition('approve', 'proposed', 'approved'),
            $this->workflow($this->guard(new Actor(7, 'operator'), true)),
        );

        $this->guard(new Actor(7, 'operator'), true)($event);

        self::assertTrue($event->isBlocked());
        self::assertSame([], $this->audit->events);
    }

    private function assertOneDecision(string $decision, string $outcome, ?int $actorId): void
    {
        self::assertCount(1, $this->audit->events);
        $event = $this->audit->events[0];
        self::assertSame($decision, $event->decision);
        self::assertSame($outcome, $event->outcome);
        self::assertSame($actorId, $event->actorId);
    }

    private function authorizer(?Actor $actor, bool $permitted): WorkflowBindingApprovalAuthorizer
    {
        return $this->build(self::access($actor), self::permission($permitted));
    }

    private function build(ConsoleAccess $access, BindingApprovalPermission $permission): WorkflowBindingApprovalAuthorizer
    {
        return new WorkflowBindingApprovalAuthorizer($this->workflow(new BindingApprovalGuard($access, $permission, $this->audit)));
    }

    private function guard(?Actor $actor, bool $permitted): BindingApprovalGuard
    {
        return new BindingApprovalGuard(self::access($actor), self::permission($permitted), $this->audit);
    }

    private function workflow(BindingApprovalGuard $guard): StateMachine
    {
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener('workflow.metric_binding.guard.approve', $guard);
        $definition = new Definition(
            ['proposed', 'approved'],
            [new Transition('approve', 'proposed', 'approved')],
            'proposed',
        );

        return new StateMachine($definition, new StatusMarkingStore(), $dispatcher, 'metric_binding');
    }

    private static function access(?Actor $actor): ConsoleAccess
    {
        return new class ($actor) implements ConsoleAccess {
            public function __construct(private ?Actor $actor) {}

            public function consoleActor(): ?Actor
            {
                return $this->actor;
            }

            public function canManageDevices(Actor $actor): bool
            {
                return true;
            }
        };
    }

    private static function permission(bool $permitted): BindingApprovalPermission
    {
        return new class ($permitted) implements BindingApprovalPermission {
            public function __construct(private bool $permitted) {}

            public function mayApproveBindings(Actor $actor): bool
            {
                return $this->permitted;
            }
        };
    }
}
