<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Kadupul\Inventory\Domain\DeviceSnmpConfiguration;
use Kadupul\Inventory\Domain\DeviceState;
use Kadupul\Inventory\Infrastructure\Legacy\DeviceSnmpWriter;
use PHPUnit\Framework\TestCase;

final class DeviceSnmpWriterTest extends TestCase
{
    #[\PHPUnit\Framework\Attributes\DataProvider('failures')]
    public function testFailedOwnedCleanupPreservesTheActualWriteFailure(bool $rejectState): void
    {
        $pdo = new class ('sqlite::memory:') extends \PDO {
            public int $rollbackAttempts = 0;
            public int $stateFailureAttempts = 0;
            public bool $rejectState = false;

            public function inTransaction(): bool
            {
                $active = parent::inTransaction();
                if ($active && $this->rejectState) {
                    $this->stateFailureAttempts++;
                    throw new \RuntimeException('fixture transaction state unavailable');
                }
                return $active;
            }

            public function fixtureIsActive(): bool
            {
                return parent::inTransaction();
            }

            public function rollBack(): bool
            {
                $this->rollbackAttempts++;
                throw new \RuntimeException('fixture rollback unavailable');
            }

            public function finishFixture(): void
            {
                parent::rollBack();
            }
        };
        $pdo->rejectState = $rejectState;
        $fields = DeviceSnmpConfiguration::PUBLIC_DEFAULTS + DeviceSnmpConfiguration::CREDENTIAL_DEFAULTS;
        $columns = implode(', ', array_map(static fn($field) => $field . ' TEXT', array_keys($fields)));
        $pdo->exec("CREATE TABLE host (id INT, poller_id INT, deleted TEXT, $columns); CREATE TABLE host_snmp_query (host_id INT,reindex_method INT); CREATE TABLE poller_reindex (host_id INT)");
        $pdo->prepare('INSERT INTO host VALUES (7,2,?,' . implode(',', array_fill(0, count($fields), '?')) . ')')->execute(['', ...array_values($fields)]);
        $pdo->exec("INSERT INTO host_snmp_query VALUES (7,1); INSERT INTO poller_reindex VALUES (7); CREATE TRIGGER reject_cleanup BEFORE DELETE ON poller_reindex BEGIN SELECT RAISE(ABORT,'fixture original write rejection'); END");
        try {
            (new DeviceSnmpWriter())->apply($pdo, new DeviceState(7, 'fixture', '192.0.2.1', true, 0, 2, 0), new DeviceSnmpConfiguration(['snmp_version' => '0'] + $fields));
            self::fail('Rejected write was accepted');
        } catch (\Throwable $failure) {
            self::assertInstanceOf(\PDOException::class, $failure);
            self::assertStringContainsString('fixture original write rejection', $failure->getMessage());
            self::assertSame($rejectState ? 0 : 1, $pdo->rollbackAttempts);
            self::assertSame($rejectState ? 1 : 0, $pdo->stateFailureAttempts);
            self::assertTrue($pdo->fixtureIsActive());
        } finally {
            if ($pdo->fixtureIsActive()) {
                $pdo->finishFixture();
            }
        }
        self::assertSame('2', $pdo->query('SELECT snmp_version FROM host WHERE id=7')->fetchColumn());
        self::assertSame(1, (int) $pdo->query('SELECT reindex_method FROM host_snmp_query WHERE host_id=7')->fetchColumn());
        self::assertSame(1, (int) $pdo->query('SELECT COUNT(*) FROM poller_reindex WHERE host_id=7')->fetchColumn());
    }

    public static function failures(): array
    {
        return [[false], [true]];
    }
    #[\PHPUnit\Framework\Attributes\DataProvider('failures')]
    public function testDisablingSnmpAtomicallyCleansReindexState(bool $reject): void
    {
        $change = new DeviceSnmpConfiguration(['snmp_version' => '0'] + DeviceSnmpConfiguration::PUBLIC_DEFAULTS + DeviceSnmpConfiguration::CREDENTIAL_DEFAULTS);
        $pdo = new \PDO('sqlite::memory:');
        $columns = implode(', ', array_map(static fn($field) => $field . ' TEXT', array_keys($change->fields)));
        $pdo->exec("CREATE TABLE host (id INT, poller_id INT, deleted TEXT, $columns); CREATE TABLE host_snmp_query (host_id INT,reindex_method INT); CREATE TABLE poller_reindex (host_id INT)");
        $pdo->exec("INSERT INTO host (id,poller_id,deleted,snmp_version) VALUES (7,2,'','2'); INSERT INTO host_snmp_query VALUES (7,1),(8,2); INSERT INTO poller_reindex VALUES (7),(8)");
        if ($reject) {
            $pdo->exec("CREATE TRIGGER reject_cleanup BEFORE DELETE ON poller_reindex BEGIN SELECT RAISE(ABORT,'fixture cleanup rejection'); END");
        }
        try {
            (new DeviceSnmpWriter())->apply($pdo, new DeviceState(7, 'Router', 'router.invalid', true, 0, 2, 0), $change);
            self::assertFalse($reject);
        } catch (\PDOException) {
            self::assertTrue($reject);
        }
        self::assertFalse($pdo->inTransaction());
        self::assertSame($reject ? '2' : '0', $pdo->query('SELECT snmp_version FROM host WHERE id=7')->fetchColumn());
        self::assertSame($reject ? 1 : 0, (int) $pdo->query('SELECT reindex_method FROM host_snmp_query WHERE host_id=7')->fetchColumn());
        self::assertSame($reject ? 1 : 0, (int) $pdo->query('SELECT COUNT(*) FROM poller_reindex WHERE host_id=7')->fetchColumn());
        self::assertSame(2, (int) $pdo->query('SELECT reindex_method FROM host_snmp_query WHERE host_id=8')->fetchColumn());
        self::assertSame(1, (int) $pdo->query('SELECT COUNT(*) FROM poller_reindex WHERE host_id=8')->fetchColumn());
    }
    private function connection(): \PDO
    {
        $fields = DeviceSnmpConfiguration::PUBLIC_DEFAULTS + DeviceSnmpConfiguration::CREDENTIAL_DEFAULTS;
        $pdo = new \PDO('sqlite::memory:', null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $columns = implode(', ', array_map(static fn($field) => $field . ' TEXT', array_keys($fields)));
        $pdo->exec("CREATE TABLE host (id INT, poller_id INT, deleted TEXT, $columns); CREATE TABLE host_snmp_query (host_id INT,reindex_method INT); CREATE TABLE poller_reindex (host_id INT); CREATE TABLE caller_work (value TEXT)");
        $pdo->prepare('INSERT INTO host VALUES (7,2,?,' . implode(',', array_fill(0, count($fields), '?')) . ')')->execute(['', ...array_values($fields)]);
        $pdo->exec('INSERT INTO host_snmp_query VALUES (7,1),(8,2); INSERT INTO poller_reindex VALUES (7),(8)');
        return $pdo;
    }

    public function testCallerOwnedTransactionRemainsActiveOnSuccessAndFailure(): void
    {
        foreach ([false, true] as $reject) {
            $pdo = $this->connection();
            if ($reject) {
                $pdo->exec("CREATE TRIGGER reject_cleanup BEFORE DELETE ON poller_reindex BEGIN SELECT RAISE(ABORT,'fixture cleanup rejection'); END");
            }
            $pdo->beginTransaction();
            $pdo->exec("INSERT INTO caller_work VALUES ('retained')");
            $change = new DeviceSnmpConfiguration(['snmp_version' => '0'] + DeviceSnmpConfiguration::PUBLIC_DEFAULTS + DeviceSnmpConfiguration::CREDENTIAL_DEFAULTS);
            try {
                (new DeviceSnmpWriter())->apply($pdo, new DeviceState(7, 'fixture', '192.0.2.1', true, 0, 2, 0), $change);
                self::assertFalse($reject);
            } catch (\PDOException) {
                self::assertTrue($reject);
            }
            self::assertTrue($pdo->inTransaction());
            self::assertSame('retained', $pdo->query('SELECT value FROM caller_work')->fetchColumn());
            $pdo->rollBack();
            self::assertSame('2', $pdo->query('SELECT snmp_version FROM host WHERE id=7')->fetchColumn());
        }
    }

    public function testWrongCollectorIdentityCannotWriteOrCommit(): void
    {
        $pdo = $this->connection();
        $change = new DeviceSnmpConfiguration(['snmp_version' => '0'] + DeviceSnmpConfiguration::PUBLIC_DEFAULTS + DeviceSnmpConfiguration::CREDENTIAL_DEFAULTS);
        try {
            (new DeviceSnmpWriter())->apply($pdo, new DeviceState(7, 'fixture', '192.0.2.1', true, 0, 3, 0), $change);
            self::fail('Wrong owner was accepted');
        } catch (\RuntimeException $error) {
            self::assertSame('Device SNMP settings could not be saved.', $error->getMessage());
        }
        self::assertFalse($pdo->inTransaction());
        self::assertSame('2', $pdo->query('SELECT snmp_version FROM host WHERE id=7')->fetchColumn());
        self::assertSame(2, (int) $pdo->query('SELECT COUNT(*) FROM poller_reindex')->fetchColumn());
    }

    public function testRewrittenStoredValueRejectsSuccessfulUpdateAndRollsBack(): void
    {
        $pdo = $this->connection();
        $pdo->exec("CREATE TRIGGER change_saved_context AFTER UPDATE ON host BEGIN UPDATE host SET snmp_context='rewritten' WHERE id=NEW.id; END");
        $change = new DeviceSnmpConfiguration(['snmp_context' => 'requested'] + DeviceSnmpConfiguration::PUBLIC_DEFAULTS + DeviceSnmpConfiguration::CREDENTIAL_DEFAULTS);
        try {
            (new DeviceSnmpWriter())->apply($pdo, new DeviceState(7, 'fixture', '192.0.2.1', true, 0, 2, 0), $change);
            self::fail('Rewritten value was accepted');
        } catch (\RuntimeException $error) {
            self::assertSame('Device SNMP settings could not be confirmed.', $error->getMessage());
        }
        self::assertFalse($pdo->inTransaction());
        self::assertSame('', $pdo->query('SELECT snmp_context FROM host WHERE id=7')->fetchColumn());
    }

    public function testEnabledSnmpPreservesReindexRows(): void
    {
        $pdo = $this->connection();
        $change = new DeviceSnmpConfiguration(DeviceSnmpConfiguration::PUBLIC_DEFAULTS + DeviceSnmpConfiguration::CREDENTIAL_DEFAULTS);
        (new DeviceSnmpWriter())->apply($pdo, new DeviceState(7, 'fixture', '192.0.2.1', true, 0, 2, 0), $change);
        self::assertSame(1, (int) $pdo->query('SELECT reindex_method FROM host_snmp_query WHERE host_id=7')->fetchColumn());
        self::assertSame(2, (int) $pdo->query('SELECT COUNT(*) FROM poller_reindex')->fetchColumn());
    }

    public function testDisabledSnmpVerificationRejectsEachKindOfLeftoverReindexState(): void
    {
        foreach (['query', 'poller'] as $leftover) {
            $pdo = $this->connection();
            $pdo->exec("UPDATE host SET snmp_version='0'");
            if ($leftover === 'query') {
                $pdo->exec('DELETE FROM poller_reindex WHERE host_id=7');
            } else {
                $pdo->exec('UPDATE host_snmp_query SET reindex_method=0 WHERE host_id=7');
            }
            $change = new DeviceSnmpConfiguration(['snmp_version' => '0'] + DeviceSnmpConfiguration::PUBLIC_DEFAULTS + DeviceSnmpConfiguration::CREDENTIAL_DEFAULTS);
            try {
                (new DeviceSnmpWriter())->verify($pdo, new DeviceState(7, 'fixture', '192.0.2.1', true, 0, 2, 0), $change);
                self::fail('Leftover reindex state was accepted');
            } catch (\RuntimeException $error) {
                self::assertSame('SNMP reindex cleanup could not be confirmed.', $error->getMessage());
            }
        }
    }

}
