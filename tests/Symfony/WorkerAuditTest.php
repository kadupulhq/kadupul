<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Kadupul\IdentityAccess\Contract\AuditEvent;
use Kadupul\IdentityAccess\Contract\AuditTrail;
use Kadupul\IdentityAccess\Infrastructure\Legacy\LegacyWorkerAudit;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class WorkerAuditTest extends TestCase
{
    public function testAcceptsParentCorrelationIdentifier(): void
    {
        $audit = new LegacyWorkerAudit($this->trail(), 'inventory.device.edit', 'device', 'unknown');

        $audit->correlate('0123456789abcdef0123456789abcdef');

        self::assertSame('0123456789abcdef0123456789abcdef', $audit->correlationId);
    }

    public static function rejectedCorrelations(): array
    {
        return [
            'missing' => [null],
            'uppercase' => ['0123456789ABCDEF0123456789ABCDEF'],
            'short' => ['0123456789abcdef'],
            'trailing newline' => ["0123456789abcdef0123456789abcdef\n"],
            'integer' => [42],
        ];
    }

    #[DataProvider('rejectedCorrelations')]
    public function testKeepsWorkerIdentifierForRejectedCorrelation(mixed $candidate): void
    {
        $events = [];
        $audit = new LegacyWorkerAudit($this->trail($events), 'inventory.device.edit', 'device', 'unknown');
        $generated = $audit->correlationId;

        try {
            $audit->correlate($candidate);
            self::fail('Malformed correlation identifier was accepted.');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame('Invalid command', $exception->getMessage());
        }
        $audit->recordAfter(null);

        self::assertMatchesRegularExpression('/^[a-f0-9]{32}$/D', $generated);
        self::assertCount(1, $events);
        self::assertSame($generated, $events[0]->correlationId);
        self::assertSame([null, AuditEvent::DENIED, AuditEvent::DENIED], [$events[0]->actorId, $events[0]->decision, $events[0]->outcome]);
    }

    public function testGeneratesDistinctIdentifiersPerWorker(): void
    {
        self::assertNotSame(
            (new LegacyWorkerAudit($this->trail(), 'inventory.device.edit', 'device', 'unknown'))->correlationId,
            (new LegacyWorkerAudit($this->trail(), 'inventory.device.edit', 'device', 'unknown'))->correlationId,
        );
    }

    public function testRecordsAfterFailingRollback(): void
    {
        $order = [];
        $trail = new class ($order) implements AuditTrail {
            public function __construct(private array &$order) {}

            public function record(AuditEvent $event): void
            {
                $this->order[] = 'record:' . $event->outcome;
            }
        };
        $audit = new LegacyWorkerAudit($trail, 'inventory.device.create', 'device', 'new');
        $audit->actorId = 42;
        $audit->decision = AuditEvent::ALLOWED;
        $audit->outcome = AuditEvent::FAILED;

        $audit->recordAfter(static function () use (&$order): never {
            $order[] = 'rollback';
            throw new \RuntimeException('Rollback failed');
        });

        self::assertSame(['rollback', 'record:failed'], $order);
    }

    public function testSinkFailureDoesNotEscape(): void
    {
        $trail = new class implements AuditTrail {
            public function record(AuditEvent $event): void
            {
                throw new \RuntimeException('Audit sink is unavailable.');
            }
        };
        $audit = new LegacyWorkerAudit($trail, 'inventory.device.assign-template', 'device', '7');
        $audit->actorId = 42;
        $audit->decision = AuditEvent::ALLOWED;
        $audit->outcome = AuditEvent::SUCCEEDED;
        $rolledBack = false;

        $audit->recordAfter(static function () use (&$rolledBack): void {
            $rolledBack = true;
        });

        self::assertTrue($rolledBack);
        self::assertSame(AuditEvent::SUCCEEDED, $audit->outcome);
    }

    public function testRejectedEventDoesNotEscape(): void
    {
        $events = [];
        $audit = new LegacyWorkerAudit($this->trail($events), 'inventory.device.edit', 'device', "7\nsecret");
        $audit->decision = AuditEvent::ALLOWED;
        $audit->outcome = AuditEvent::SUCCEEDED;

        $audit->recordAfter(null);

        self::assertSame([], $events);
    }

    public function testRecordsResolvedFields(): void
    {
        $events = [];
        $audit = new LegacyWorkerAudit($this->trail($events), 'inventory.device.create', 'device', 'new');
        $audit->correlate('0123456789abcdef0123456789abcdef');
        $audit->actorId = 42;
        $audit->targetId = '9';
        $audit->decision = AuditEvent::ALLOWED;
        $audit->outcome = AuditEvent::SUCCEEDED;

        $audit->recordAfter(null);

        self::assertCount(1, $events);
        $record = json_decode($events[0]->json(), true, 8, JSON_THROW_ON_ERROR);
        unset($record['recorded_at']);
        self::assertSame([
            'schema' => 'kadupul.audit.v1',
            'correlation_id' => '0123456789abcdef0123456789abcdef',
            'actor' => ['id' => 42],
            'action' => 'inventory.device.create',
            'target' => ['type' => 'device', 'id' => '9'],
            'decision' => 'allowed',
            'outcome' => 'succeeded',
        ], $record);
    }

    /** @param list<AuditEvent> $events */
    private function trail(array &$events = []): AuditTrail
    {
        return new class ($events) implements AuditTrail {
            public function __construct(private array &$events) {}

            public function record(AuditEvent $event): void
            {
                $this->events[] = $event;
            }
        };
    }
}
