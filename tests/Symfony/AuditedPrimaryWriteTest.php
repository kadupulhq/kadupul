<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Kadupul\IdentityAccess\Contract\AuditEvent;
use Kadupul\IdentityAccess\Contract\AuditTrail;
use Kadupul\Platform\Contract\LegacyConfiguration;
use Kadupul\Platform\Infrastructure\Persistence\AuditedPrimaryWrite;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AuditedPrimaryWriteTest extends TestCase
{
    private Connection $database;
    /** @var list<AuditEvent> */
    private array $events = [];
    /** @var list<bool> */
    private array $checkedInTransaction = [];
    private bool $revoked = false;

    protected function setUp(): void
    {
        $this->database = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->database->executeStatement('CREATE TABLE widget (id INTEGER PRIMARY KEY, name TEXT NOT NULL)');
    }

    public function testACommittedWriteIsAuditedAsSucceededWithTheStoreNouns(): void
    {
        $id = $this->write()->run(7, 'create', 'new', function (): int {
            $this->database->insert('widget', ['name' => 'one']);

            return (int) $this->database->lastInsertId();
        });

        self::assertSame(1, $id);
        self::assertSame([true], $this->checkedInTransaction, 'the actor is rechecked inside the transaction');
        self::assertFalse($this->database->isTransactionActive());
        self::assertSame(1, (int) $this->database->fetchOne('SELECT COUNT(*) FROM widget'));
        self::assertSame([[7, 'test.widget.create', 'widget', 'new', AuditEvent::ALLOWED, AuditEvent::SUCCEEDED]], $this->audited());
    }

    public function testAFailedOperationRollsBackAndKeepsItsError(): void
    {
        $failure = new \InvalidArgumentException('Widget is in use.');
        try {
            $this->write()->run(7, 'delete', '3', function () use ($failure): void {
                $this->database->insert('widget', ['name' => 'partial']);

                throw $failure;
            });
            self::fail('The failed operation was reported as written.');
        } catch (\InvalidArgumentException $error) {
            self::assertSame($failure, $error);
        }

        self::assertFalse($this->database->isTransactionActive());
        self::assertSame(0, (int) $this->database->fetchOne('SELECT COUNT(*) FROM widget'));
        self::assertSame([[7, 'test.widget.delete', 'widget', '3', AuditEvent::ALLOWED, AuditEvent::FAILED]], $this->audited());
    }

    public function testARevokedActorIsDeniedBeforeTheOperationRuns(): void
    {
        $this->revoked = true;
        $ran = false;
        try {
            $this->write()->run(7, 'edit', '1', function () use (&$ran): void {
                $ran = true;
            });
            self::fail('A revoked actor was allowed to write.');
        } catch (\DomainException) {
        }

        self::assertFalse($ran);
        self::assertFalse($this->database->isTransactionActive());
        self::assertSame([[7, 'test.widget.edit', 'widget', '1', AuditEvent::DENIED, AuditEvent::DENIED]], $this->audited());
    }

    public function testAWriteInsideACallerTransactionIsRefused(): void
    {
        $this->database->beginTransaction();
        try {
            $this->write()->run(7, 'edit', '1', static fn(): null => null);
            self::fail('A write joined a transaction it does not own.');
        } catch (\RuntimeException $error) {
            self::assertSame('Widget writes require their own transaction.', $error->getMessage());
        } finally {
            $this->database->rollBack();
        }

        self::assertSame([], $this->checkedInTransaction);
        self::assertSame([[7, 'test.widget.edit', 'widget', '1', AuditEvent::DENIED, AuditEvent::DENIED]], $this->audited());
    }

    public function testAnAuditFailureCannotChangeACommittedResult(): void
    {
        $audit = $this->createMock(AuditTrail::class);
        $audit->method('record')->willThrowException(new \RuntimeException('audit table full'));
        $write = new AuditedPrimaryWrite($this->database, $audit, $this->configuration(1), static function (): void {}, ['widget'], 'Widget', 'Widgets', 'test.widget', 'widget');

        self::assertSame('kept', $write->run(7, 'create', 'new', static fn(): string => 'kept'));
    }

    public function testMysqlWritesRunOnlyOnThePrimaryCollector(): void
    {
        $database = $this->createMock(Connection::class);
        $database->method('getDatabasePlatform')->willReturn(new MariaDBPlatform());
        $database->expects(self::never())->method('beginTransaction');
        $database->expects(self::never())->method('fetchNumeric');

        $this->assertPrepareRefuses(new AuditedPrimaryWrite($database, $this->audit(), $this->configuration(2), static function (): void {}, ['widget'], 'Widget', 'Widgets', 'test.widget', 'widget'), 'Widgets must be changed on the primary collector.');
    }

    /** @return iterable<string, array{0: list<mixed>|false}> */
    public static function nonTransactionalDefinitions(): iterable
    {
        yield 'missing' => [false];
        yield 'MyISAM' => [['widget', "CREATE TABLE `widget` (\n  `id` int\n) ENGINE=MyISAM"]];
        yield 'engine only in a comment' => [['widget', "CREATE TEMPORARY TABLE `widget` (\n  `id` int COMMENT ') ENGINE=InnoDB'\n) ENGINE=MEMORY"]];
    }

    /** @param list<mixed>|false $definition */
    #[DataProvider('nonTransactionalDefinitions')]
    public function testMysqlWritesRequireInnodbParticipants(array|false $definition): void
    {
        $database = $this->createMock(Connection::class);
        $database->method('getDatabasePlatform')->willReturn(new MariaDBPlatform());
        $database->method('quoteIdentifier')->willReturnCallback(static fn(string $name): string => '`' . $name . '`');
        $database->method('fetchNumeric')->willReturn($definition);
        $database->expects(self::never())->method('executeStatement');

        $this->assertPrepareRefuses(new AuditedPrimaryWrite($database, $this->audit(), $this->configuration(1), static function (): void {}, ['widget'], 'Widget', 'Widgets', 'test.widget', 'widget'), 'Widget writes require transactional tables.');
    }

    public function testMysqlChecksEveryParticipantThenTheStoreCheckThenTheIsolation(): void
    {
        $steps = [];
        $database = $this->createMock(Connection::class);
        $database->method('getDatabasePlatform')->willReturn(new MariaDBPlatform());
        $database->method('quoteIdentifier')->willReturnCallback(static fn(string $name): string => '`' . $name . '`');
        $database->method('fetchNumeric')->willReturnCallback(static function (string $sql) use (&$steps): array {
            $steps[] = $sql;

            return ['t', "CREATE TABLE `t` (\n  `id` int\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"];
        });
        $database->method('executeStatement')->willReturnCallback(static function (string $sql) use (&$steps): int {
            $steps[] = $sql;

            return 0;
        });
        $write = new AuditedPrimaryWrite($database, $this->audit(), $this->configuration(1), static function (): void {}, ['widget', 'settings'], 'Widget', 'Widgets', 'test.widget', 'widget', static function () use (&$steps): void {
            $steps[] = 'store check';
        });

        $write->prepare();

        self::assertSame(['SHOW CREATE TABLE `widget`', 'SHOW CREATE TABLE `settings`', 'store check', 'SET TRANSACTION ISOLATION LEVEL REPEATABLE READ'], $steps);
    }

    /** @return iterable<string, array{0: list<int>, 1: array<int, mixed>, 2: string}> */
    public static function refusedSelections(): iterable
    {
        yield 'empty' => [[], [], 'invalid'];
        yield 'too many' => [[1, 2, 3, 4], [1 => 'a', 2 => 'b', 3 => 'c', 4 => 'd'], 'invalid'];
        yield 'unsorted' => [[2, 1], [1 => 'a', 2 => 'b'], 'invalid'];
        yield 'repeated' => [[1, 1], [1 => 'a'], 'invalid'];
        yield 'zero' => [[0], [0 => 'a'], 'invalid'];
        yield 'above the column' => [[100], [100 => 'a'], 'invalid'];
        yield 'not a list' => [[1 => 1], [1 => 'a'], 'invalid'];
        yield 'missing revision' => [[1, 2], [1 => 'a'], 'stale'];
        yield 'extra revision' => [[1], [1 => 'a', 2 => 'b'], 'stale'];
        yield 'revision not text' => [[1], [1 => 5], 'stale'];
    }

    /**
     * @param list<int> $ids
     * @param array<int, mixed> $revisions
     */
    #[DataProvider('refusedSelections')]
    public function testASelectionMustBeSortedUniqueBoundedAndMatchItsRevisions(array $ids, array $revisions, string $message): void
    {
        $this->expectExceptionObject(new \InvalidArgumentException($message));

        AuditedPrimaryWrite::assertSelection($ids, $revisions, 3, 99, 'invalid', 'stale');
    }

    public function testAValidSelectionIsAccepted(): void
    {
        AuditedPrimaryWrite::assertSelection([1, 5, 99], [99 => 'c', 1 => 'a', 5 => 'b'], 3, 99, 'invalid', 'stale');

        $this->addToAssertionCount(1);
    }

    private function assertPrepareRefuses(AuditedPrimaryWrite $write, string $message): void
    {
        try {
            $write->prepare();
            self::fail('The write was prepared.');
        } catch (\RuntimeException $error) {
            self::assertSame($message, $error->getMessage());
        }
    }

    private function write(): AuditedPrimaryWrite
    {
        return new AuditedPrimaryWrite($this->database, $this->audit(), $this->configuration(1), function (int $actorId): void {
            self::assertSame(7, $actorId);
            $this->checkedInTransaction[] = $this->database->isTransactionActive();
            if ($this->revoked) {
                throw new \DomainException('revoked');
            }
        }, ['widget'], 'Widget', 'Widgets', 'test.widget', 'widget');
    }

    private function audit(): AuditTrail
    {
        $audit = $this->createMock(AuditTrail::class);
        $audit->method('record')->willReturnCallback(function (AuditEvent $event): void {
            $this->events[] = $event;
        });

        return $audit;
    }

    private function configuration(int $collector): LegacyConfiguration
    {
        $configuration = $this->createMock(LegacyConfiguration::class);
        $configuration->method('values')->willReturn(['collector_id' => $collector]);

        return $configuration;
    }

    /** @return list<array{0: ?int, 1: string, 2: string, 3: string, 4: string, 5: string}> */
    private function audited(): array
    {
        return array_map(static fn(AuditEvent $event): array => [$event->actorId, $event->action, $event->targetType, $event->targetId, $event->decision, $event->outcome], $this->events);
    }
}
