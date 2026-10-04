<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Kadupul\Inventory\Infrastructure\Legacy\QueuedCollectorPurge;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class QueuedCollectorPurgeTest extends TestCase
{
    private static ?PDO $administrator = null;
    private static array $schemas = [];
    private static array $dsns = [];
    private static string $user;
    private static string $password;

    public static function setUpBeforeClass(): void
    {
        self::$user = getenv('KADUPUL_TEST_MYSQL_USER') ?: 'root';
        self::$password = getenv('KADUPUL_TEST_MYSQL_PASSWORD') ?: '';
        if (getenv('KADUPUL_PURGE_PRIMARY_DSN') && getenv('KADUPUL_PURGE_REMOTE_DSN')) {
            self::$dsns = ['primary' => getenv('KADUPUL_PURGE_PRIMARY_DSN'), 'remote' => getenv('KADUPUL_PURGE_REMOTE_DSN')];
            return;
        }
        $dsn = getenv('KADUPUL_TEST_MYSQL_DSN');
        if (!$dsn) {
            return;
        }
        self::$user = getenv('KADUPUL_TEST_MYSQL_ADMIN_USER') ?: self::$user;
        self::$password = getenv('KADUPUL_TEST_MYSQL_ADMIN_PASSWORD') ?: self::$password;
        self::$administrator = new PDO($dsn, self::$user, self::$password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        try {
            foreach (['primary', 'remote'] as $role) {
                $schema = 'queued_purge_' . bin2hex(random_bytes(10));
                self::$administrator->exec('CREATE DATABASE `' . $schema . '`');
                self::$schemas[] = $schema;
                self::$dsns[$role] = preg_replace('/dbname=[^;]+/', 'dbname=' . $schema, $dsn);
            }
        } catch (\Throwable $error) {
            self::tearDownAfterClass();
            throw $error;
        }
    }

    public static function tearDownAfterClass(): void
    {
        foreach (self::$schemas as $schema) {
            self::$administrator->exec('DROP DATABASE `' . $schema . '`');
        }
        self::$schemas = [];
        self::$administrator = null;
    }

    private PDO $primary;
    private PDO $remote;
    private array $command;
    private array $observations = [];

    protected function setUp(): void
    {
        if (count(self::$dsns) !== 2) {
            self::markTestSkipped('Two owned native database schemas are required.');
        }
        if (!defined('POLLER_COMMAND_PURGE')) {
            require dirname(__DIR__, 2) . '/include/global_constants.php';
        }
        foreach (self::$dsns as $property => $dsn) {
            $this->$property = new PDO($dsn, self::$user, self::$password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $connection = $this->$property;
            foreach (['host', 'host_graph', 'host_snmp_query', 'host_snmp_cache', 'poller_item', 'poller_reindex', 'graph_tree_items', 'reports_items', 'poller_command', 'data_local', 'graph_local', 'data_input_data', 'data_template_rrd', 'data_template_data', 'graph_templates_item'] as $table) {
                $connection->exec("DROP TABLE IF EXISTS $table");
                $ddl = match ($table) {
                    'host' => "id MEDIUMINT UNSIGNED PRIMARY KEY, poller_id INT UNSIGNED NOT NULL, deleted CHAR(2) NOT NULL DEFAULT ''",
                    'poller_command' => 'poller_id SMALLINT UNSIGNED NOT NULL, action TINYINT UNSIGNED NOT NULL, command VARCHAR(191) NOT NULL, time TIMESTAMP NOT NULL, last_updated TIMESTAMP NOT NULL, PRIMARY KEY(poller_id,action,command)',
                    default => 'host_id MEDIUMINT UNSIGNED NOT NULL',
                };
                $connection->exec("CREATE TABLE $table ($ddl) ENGINE=InnoDB");
            }
            $connection->exec('SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED');
            $connection->exec('SET SESSION innodb_lock_wait_timeout = 7');
            $connection->exec('SET SESSION lock_wait_timeout = 8');
        }
        $this->primary->exec("INSERT INTO host VALUES (7,1,'')");
        $this->remote->exec("INSERT INTO host VALUES (7,3,'')");
        $this->remote->exec('INSERT INTO poller_item VALUES(7)');
        $this->command = ['action' => POLLER_COMMAND_PURGE, 'command' => '7', 'time' => '2026-10-03 01:00:00', 'last_updated' => '2026-10-03 01:00:01'];
        $this->primary->prepare('INSERT INTO poller_command VALUES (3,?,?,?,?)')->execute(array_values($this->command));
    }

    private function execute(?callable $purge = null): string
    {
        return (new QueuedCollectorPurge(new \Kadupul\Platform\Infrastructure\Legacy\NativeReferenceWriteTransactionRunner()))->run(
            $this->primary,
            3,
            $this->command,
            fn(): PDO => $this->remote,
            $purge === null ? function (PDO $remote, int $id): bool {
                $this->observations[] = [$this->primary->inTransaction(), $remote->inTransaction()];
                $remote->prepare('DELETE FROM poller_item WHERE host_id=?')->execute([$id]);
                $remote->prepare('DELETE FROM host WHERE id=?')->execute([$id]);
                $this->observations[] = (int) $remote->query('SELECT COUNT(*) FROM host')->fetchColumn();
                return true;
            } : \Closure::fromCallable($purge)
        );
    }

    private function rowCount(PDO $database, string $table): int
    {
        return (int) $database->query("SELECT COUNT(*) FROM $table")->fetchColumn();
    }

    private function assertRestored(): void
    {
        foreach ([$this->primary, $this->remote] as $connection) {
            self::assertFalse($connection->inTransaction());
            $rows = $connection->query("SHOW SESSION VARIABLES WHERE Variable_name IN ('transaction_isolation','tx_isolation','innodb_lock_wait_timeout','lock_wait_timeout')")->fetchAll(PDO::FETCH_KEY_PAIR);
            self::assertSame('READ-COMMITTED', $rows['transaction_isolation'] ?? $rows['tx_isolation']);
            self::assertSame('7', $rows['innodb_lock_wait_timeout']);
            self::assertSame('8', $rows['lock_wait_timeout']);
        }
    }

    public function testHealthyPurgeCommitsRemoteAbsenceBeforeExactAcknowledgement(): void
    {
        self::assertSame('purged', $this->execute());
        self::assertSame([[true, true], 0], $this->observations);
        self::assertSame(0, $this->rowCount($this->remote, 'host'));
        self::assertSame(0, $this->rowCount($this->remote, 'poller_item'));
        self::assertSame(0, $this->rowCount($this->primary, 'poller_command'));
        self::assertSame(1, (int) $this->primary->query('SELECT poller_id FROM host')->fetchColumn());
        $this->assertRestored();
    }

    public function testReturningCurrentOwnerCancelsStaleCommandWithoutRemoteConnection(): void
    {
        $this->primary->exec('UPDATE host SET poller_id=3');
        $calls = 0;
        $result = (new QueuedCollectorPurge(new \Kadupul\Platform\Infrastructure\Legacy\NativeReferenceWriteTransactionRunner()))->run($this->primary, 3, $this->command, function () use (&$calls): PDO {
            $calls++;
            return $this->remote;
        }, static fn(): bool => throw new RuntimeException('Forbidden purge'));
        self::assertSame('obsolete', $result);
        self::assertSame(0, $calls);
        self::assertSame(1, $this->rowCount($this->remote, 'host'));
        self::assertSame(1, $this->rowCount($this->remote, 'poller_item'));
        self::assertSame(0, $this->rowCount($this->primary, 'poller_command'));
        $this->assertRestored();
    }

    public function testChangedTimestampRetainsNewerCommandAndRemoteCopy(): void
    {
        $this->primary->exec("UPDATE poller_command SET last_updated='2026-10-03 01:00:02'");
        self::assertSame('changed', $this->execute(static fn(): bool => throw new RuntimeException('Forbidden purge')));
        self::assertSame(1, $this->rowCount($this->primary, 'poller_command'));
        self::assertSame(1, $this->rowCount($this->remote, 'host'));
        $this->assertRestored();
    }

    public function testAbsentQueueCannotPurgeAnyCopy(): void
    {
        $this->primary->exec('DELETE FROM poller_command');
        self::assertSame('changed', $this->execute(static fn(): bool => throw new RuntimeException('Forbidden purge')));
        self::assertSame(1, $this->rowCount($this->remote, 'host'));
        $this->assertRestored();
    }

    public function testDeletedPrimaryDeviceStillAllowsItsLegitimateCleanup(): void
    {
        $this->primary->exec("UPDATE host SET poller_id=3,deleted='on'");
        self::assertSame('purged', $this->execute());
        self::assertSame(0, $this->rowCount($this->remote, 'host'));
        self::assertSame(0, $this->rowCount($this->primary, 'poller_command'));
        $this->assertRestored();
    }

    public function testMissingPrimaryDeviceStillAllowsVerifiedCleanup(): void
    {
        $this->primary->exec('DELETE FROM host');
        self::assertSame('purged', $this->execute());
        self::assertSame(0, $this->rowCount($this->remote, 'host'));
        $this->assertRestored();
    }

    public function testFailedRemotePurgeRollsBackRemoteAndRetainsQueue(): void
    {
        $failure = null;
        try {
            $this->execute(static function (PDO $remote): bool {
                $remote->exec('DELETE FROM host');
                throw new RuntimeException('Actual callback failure');
            });
        } catch (RuntimeException $error) {
            $failure = $error;
        }
        self::assertInstanceOf(RuntimeException::class, $failure);
        self::assertSame('Actual callback failure', $failure->getMessage());
        self::assertSame(1, $this->rowCount($this->remote, 'host'));
        self::assertSame(1, $this->rowCount($this->primary, 'poller_command'));
        $this->assertRestored();
    }

    public function testLateAcknowledgementMismatchRetainsCommandAfterCommittedRemoteAbsence(): void
    {
        $failure = null;
        try {
            $this->execute(function (PDO $remote): bool {
                $remote->exec('DELETE FROM host');
                $this->primary->exec("UPDATE poller_command SET time='2026-10-03 01:00:09'");
                return true;
            });
        } catch (RuntimeException $error) {
            $failure = $error;
        }
        self::assertInstanceOf(RuntimeException::class, $failure);
        self::assertSame('Queued command acknowledgement changed', $failure->getMessage());
        self::assertSame(0, $this->rowCount($this->remote, 'host'));
        self::assertSame(1, $this->rowCount($this->primary, 'poller_command'));
        self::assertSame('2026-10-03 01:00:00', $this->primary->query('SELECT time FROM poller_command')->fetchColumn());
        $this->assertRestored();
    }

    public function testCallerOwnedPrimaryTransactionIsPreserved(): void
    {
        $this->primary->beginTransaction();
        $failure = null;
        try {
            $this->execute();
        } catch (RuntimeException $error) {
            $failure = $error;
        }
        self::assertInstanceOf(RuntimeException::class, $failure);
        self::assertSame('Owned collector transaction unavailable', $failure->getMessage());
        self::assertTrue($this->primary->inTransaction());
        self::assertSame(1, $this->rowCount($this->remote, 'host'));
        $this->primary->rollBack();
    }

    public function testNontransactionalPrimaryQueueRefusesAllRemoteEffects(): void
    {
        $this->primary->exec('ALTER TABLE poller_command ENGINE=MyISAM');
        $failure = null;
        try {
            $this->execute(static fn(): bool => throw new RuntimeException('Forbidden purge'));
        } catch (RuntimeException $error) {
            $failure = $error;
        }
        self::assertInstanceOf(RuntimeException::class, $failure);
        self::assertStringContainsString('persistent transactional', $failure->getMessage());
        self::assertSame(1, $this->rowCount($this->remote, 'host'));
        self::assertSame(1, $this->rowCount($this->primary, 'poller_command'));
        $this->assertRestored();
    }

    public function testOfflineCollectorCannotUseItsLocalQueueAsPrimary(): void
    {
        $this->expectException(RuntimeException::class);
        QueuedCollectorPurge::primary(['poller_id' => 3,'connection' => 'offline'], ['local' => $this->remote], 'local', $this->primary);
    }

    public function testPrimaryAndOnlineCollectorResolveTheActualAuthoritativeConnection(): void
    {
        self::assertSame($this->primary, QueuedCollectorPurge::primary(['poller_id' => 1], ['primary' => $this->primary], 'primary', $this->remote));
        self::assertSame($this->primary, QueuedCollectorPurge::primary(['poller_id' => 3,'connection' => 'online'], ['local' => $this->remote], 'local', $this->primary));
    }

    public function testNonPurgeAcknowledgementRetainsDifferentExactCommandBytes(): void
    {
        $other = ['action' => 99,'command' => '7:Unknown','time' => '2026-10-03 01:00:00','last_updated' => '2026-10-03 01:00:01'];
        $this->primary->prepare('INSERT INTO poller_command VALUES (3,?,?,?,?)')->execute(array_values($other));
        $this->primary->prepare('INSERT INTO poller_command VALUES (3,?,?,?,?)')->execute([99,'7:Another', $other['time'], $other['last_updated']]);
        $failure = null;
        try {
            (new QueuedCollectorPurge(new \Kadupul\Platform\Infrastructure\Legacy\NativeReferenceWriteTransactionRunner()))->acknowledge($this->primary, 3, [...$other, 'command' => '7:Unknown ']);
        } catch (RuntimeException $error) {
            $failure = $error;
        }
        self::assertInstanceOf(RuntimeException::class, $failure);
        self::assertSame('Queued command acknowledgement changed', $failure->getMessage());
        self::assertSame(3, $this->rowCount($this->primary, 'poller_command'));
        (new QueuedCollectorPurge(new \Kadupul\Platform\Infrastructure\Legacy\NativeReferenceWriteTransactionRunner()))->acknowledge($this->primary, 3, $other);
        self::assertSame(2, $this->rowCount($this->primary, 'poller_command'));
        self::assertSame('7:Another', $this->primary->query('SELECT command FROM poller_command WHERE action=99')->fetchColumn());
    }

    private function peer(string $role): PDO
    {
        $connection = new PDO(self::$dsns[$role], self::$user, self::$password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $connection->exec('SET SESSION innodb_lock_wait_timeout = 1');
        return $connection;
    }

    public function testActualPeerReturningAssignmentCannotOvertakeLockedPurge(): void
    {
        $primaryPeer = $this->peer('primary');
        $remotePeer = $this->peer('remote');
        $errors = [];
        $result = $this->execute(function (PDO $remote) use ($primaryPeer, $remotePeer, &$errors): bool {
            foreach ([[$primaryPeer, 'UPDATE host SET poller_id=3 WHERE id=7'], [$remotePeer, 'UPDATE host SET poller_id=3 WHERE id=7']] as [$peer, $sql]) {
                try {
                    $peer->exec($sql);
                } catch (\PDOException $error) {
                    $errors[] = $error->errorInfo[1] ?? null;
                }
            }
            $remote->exec('DELETE FROM poller_item');
            $remote->exec('DELETE FROM host');
            return true;
        });
        self::assertSame('purged', $result);
        self::assertSame([1205,1205], $errors);
        self::assertSame(0, $this->rowCount($this->remote, 'host'));
        self::assertSame(0, $this->rowCount($this->primary, 'poller_command'));
        // A subsequent legitimate returning assignment succeeds after both owned locks release.
        $primaryPeer->exec('UPDATE host SET poller_id=3 WHERE id=7');
        $remotePeer->exec("INSERT INTO host VALUES(7,3,'')");
        self::assertSame('3', (string) $primaryPeer->query('SELECT poller_id FROM host WHERE id=7')->fetchColumn());
        self::assertSame(1, $this->rowCount($remotePeer, 'host'));
        $this->assertRestored();
    }

    public function testMissingRemoteHostNextKeyLockBlocksReturningInsert(): void
    {
        $this->remote->exec('DELETE FROM host');
        $peer = $this->peer('remote');
        $failure = null;
        $result = $this->execute(function (PDO $remote) use ($peer, &$failure): bool {
            try {
                $peer->exec("INSERT INTO host VALUES(7,3,'')");
            } catch (\PDOException $error) {
                $failure = $error;
            }
            $remote->exec('DELETE FROM poller_item');
            return true;
        });
        self::assertSame('purged', $result);
        self::assertInstanceOf(\PDOException::class, $failure);
        self::assertSame(1205, $failure->errorInfo[1]);
        self::assertSame(0, $this->rowCount($this->remote, 'host'));
        $peer->exec("INSERT INTO host VALUES(7,3,'')");
        self::assertSame(1, $this->rowCount($peer, 'host'));
        $this->assertRestored();
    }

    public function testFalsePurgeOutcomeRollsBackAndRetainsExactCommand(): void
    {
        $failure = null;
        try {
            $this->execute(static function (PDO $remote): bool {
                $remote->exec('DELETE FROM host');
                return false;
            });
        } catch (RuntimeException $error) {
            $failure = $error;
        }
        self::assertInstanceOf(RuntimeException::class, $failure);
        self::assertSame('Collector purge could not be confirmed', $failure->getMessage());
        self::assertSame(1, $this->rowCount($this->remote, 'host'));
        self::assertSame($this->command['time'], $this->primary->query('SELECT time FROM poller_command')->fetchColumn());
        $this->assertRestored();
    }

    public function testCallerOwnedRemoteTransactionIsNeitherCommittedNorRolledBack(): void
    {
        $this->remote->beginTransaction();
        $this->remote->exec('DELETE FROM poller_item');
        $failure = null;
        try {
            $this->execute();
        } catch (RuntimeException $error) {
            $failure = $error;
        }
        self::assertInstanceOf(RuntimeException::class, $failure);
        self::assertSame('Owned collector transaction unavailable', $failure->getMessage());
        self::assertTrue($this->remote->inTransaction());
        self::assertSame(0, $this->rowCount($this->remote, 'poller_item'));
        self::assertSame(1, $this->rowCount($this->primary, 'poller_command'));
        $this->remote->rollBack();
        self::assertSame(1, $this->rowCount($this->remote, 'poller_item'));
        $this->assertRestored();
    }

    public function testReturningOwnerCommittedByAnotherSessionIsReadCurrently(): void
    {
        self::assertSame('1', (string) $this->primary->query('SELECT poller_id FROM host WHERE id=7')->fetchColumn());
        $peer = $this->peer('primary');
        $peer->beginTransaction();
        $peer->exec('UPDATE host SET poller_id=3 WHERE id=7');
        $peer->commit();
        self::assertSame('obsolete', $this->execute(static fn(): bool => throw new RuntimeException('Forbidden purge')));
        self::assertSame(1, $this->rowCount($this->remote, 'host'));
        self::assertSame(0, $this->rowCount($this->primary, 'poller_command'));
        $this->assertRestored();
    }

    public function testActualConsumerReturningAssignmentRetainsCurrentCopyAfterFetchedCommandCancellation(): void
    {
        $value = $this->consumer('return');
        self::assertCount(1, $value['fetched']);
        self::assertSame('3', (string) $value['primary_owner']);
        self::assertSame([], $value['queue']);
        self::assertSame('1', (string) $value['remote_host']);
        self::assertSame('1', (string) $value['remote_polling']);
        self::assertStringContainsString('Stale command skipped', implode("\n", $value['events']));
        self::assertStringNotContainsString('Purged successfully', implode("\n", $value['events']));
    }

    public function testActualConsumerCurrentOwnerAcknowledgesWithoutDeletingCurrentCopy(): void
    {
        $value = $this->consumer('current');
        self::assertSame([], $value['queue']);
        self::assertSame('3', (string) $value['primary_owner']);
        self::assertSame('1', (string) $value['remote_host']);
        self::assertSame('1', (string) $value['remote_polling']);
        self::assertStringContainsString('Stale command skipped', implode("\n", $value['events']));
    }

    public function testActualConsumerOfflineRoleFailureRetainsCommandAndCopy(): void
    {
        $value = $this->consumer('offline');
        self::assertCount(1, $value['queue']);
        self::assertSame($this->command['command'], $value['queue'][0]['command']);
        self::assertSame($this->command['time'], $value['queue'][0]['time']);
        self::assertSame('1', (string) $value['remote_host']);
        self::assertStringContainsString('verify persisted state before retry', implode("\n", $value['events']));
        self::assertStringNotContainsString('Purged successfully', implode("\n", $value['events']));
    }

    public function testActualConsumerUnknownCommandAcknowledgesOnlyFetchedIdentity(): void
    {
        $value = $this->consumer('unknown');
        self::assertCount(1, $value['queue']);
        self::assertSame('7:Added', $value['queue'][0]['command']);
        self::assertSame('88', (string) $value['queue'][0]['action']);
        self::assertSame('1', (string) $value['remote_host']);
        self::assertStringContainsString('Unknown poller command issued', implode("\n", $value['events']));
    }

    public function testActualConsumerEmptyQueueDoesNotTouchCurrentCopy(): void
    {
        $value = $this->consumer('empty');
        self::assertSame([], $value['fetched']);
        self::assertSame([], $value['queue']);
        self::assertSame('1', (string) $value['remote_host']);
        self::assertSame('1', (string) $value['remote_polling']);
        self::assertSame([], $value['events']);
    }


    public function testPostCommitRestorationFaultDoesNotInventRetainedCommand(): void
    {
        $this->primary = new QueuedPurgeFaultPdo(self::$dsns['primary'], self::$user, self::$password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $failure = null;
        try {
            $this->execute();
        } catch (RuntimeException $error) {
            $failure = $error;
        }
        self::assertInstanceOf(RuntimeException::class, $failure);
        self::assertSame('Collector session restoration failed', $failure->getMessage());
        self::assertFalse($this->primary->inTransaction());
        self::assertSame(0, $this->rowCount($this->remote, 'host'));
        self::assertSame(0, $this->rowCount($this->remote, 'poller_item'));
        self::assertSame(0, $this->rowCount($this->primary, 'poller_command'));
    }

    public function testActualConsumerPostCommitRestorationFailureLogsUncertaintyWithQueueGone(): void
    {
        $value = $this->consumer('restore-fault');
        self::assertSame([], $value['queue']);
        self::assertSame('3', (string) $value['primary_owner']);
        self::assertSame('1', (string) $value['remote_host']);
        self::assertStringContainsString('verify persisted state before retry', implode("\n", $value['events']));
        self::assertStringNotContainsString('retained for retry', implode("\n", $value['events']));
        self::assertStringNotContainsString('Purged successfully', implode("\n", $value['events']));
    }

    public function testActualConsumerPostDeleteReadFailureLogsUncertaintyWithQueueGone(): void
    {
        $value = $this->consumer('ack-fault');
        self::assertSame([], $value['queue']);
        self::assertSame('1', (string) $value['remote_host']);
        self::assertStringContainsString('acknowledgement could not be confirmed; verify persisted state', implode("\n", $value['events']));
        self::assertStringNotContainsString('retained for retry', implode("\n", $value['events']));
    }


    public function testActualHealthyConsumerPurgesAllDependentsBeforeAcknowledgingWithoutEnqueue(): void
    {
        $value = $this->consumer('healthy');
        $this->assertHealthyDependencyIdentity($value);
        self::assertSame([], $value['queue']);
        self::assertSame('1', (string) $value['primary_owner']);
        foreach ($value['dependents'] as $table => $count) {
            self::assertSame(0, $count, $table);
        }
        self::assertStringContainsString('Purged successfully', implode("\n", $value['events']));
        self::assertStringNotContainsString('ERROR', implode("\n", $value['events']));
    }

    public function testActualHealthyConsumerFailedRemoteDeleteRestoresAllDependentsAndRetainsCommand(): void
    {
        $value = $this->consumer('healthy-fail');
        $this->assertHealthyDependencyIdentity($value);
        self::assertCount(1, $value['queue']);
        self::assertSame($this->command['time'], $value['queue'][0]['time']);
        foreach ($value['dependents'] as $table => $count) {
            self::assertSame(1, $count, $table);
        }
        self::assertStringContainsString('verify persisted state before retry', implode("\n", $value['events']));
        self::assertStringNotContainsString('Purged successfully', implode("\n", $value['events']));
    }

    public function testActualHealthyConsumerDeletedCurrentDeviceAllowsLegitimateCleanup(): void
    {
        $value = $this->consumer('healthy-deleted');
        $this->assertHealthyDependencyIdentity($value);
        self::assertSame([], $value['queue']);
        self::assertSame('3', (string) $value['primary_owner']);
        foreach ($value['dependents'] as $table => $count) {
            self::assertSame(0, $count, $table);
        }
        self::assertStringContainsString('Purged successfully', implode("\n", $value['events']));
    }

    public function testActualHealthyConsumerMissingPrimaryDeviceAllowsVerifiedLegitimateCleanup(): void
    {
        $value = $this->consumer('healthy-missing');
        $this->assertHealthyDependencyIdentity($value);
        self::assertSame([], $value['queue']);
        self::assertFalse($value['primary_owner']);
        foreach ($value['dependents'] as $table => $count) {
            self::assertSame(0, $count, $table);
        }
        self::assertStringContainsString('Purged successfully', implode("\n", $value['events']));
    }

    private function assertHealthyDependencyIdentity(array $value): void
    {
        self::assertSame(hash_file('sha256', dirname(__DIR__, 2) . '/lib/api_device.php'), $value['api_sha256']);
        self::assertSame(hash_file('sha256', dirname(__DIR__, 2) . '/src/Inventory/Infrastructure/Legacy/DeviceCollectorReplication.php'), $value['verifier_sha256']);
        self::assertSame(0, $value['api_enqueues']);
        self::assertCount(15, $value['dependents']);
        self::assertCount(15, $value['unselected_dependents']);
        foreach ($value['unselected_dependents'] as $table => $count) {
            self::assertSame(1, $count, $table);
        }
        self::assertSame(1, $value['unselected_host']);
        self::assertSame(1, $value['unselected_polling']);
    }

    private function consumer(string $mode): array
    {
        $process = new \Symfony\Component\Process\Process([PHP_BINARY, '-d', 'zend.exception_ignore_args=1', dirname(__DIR__) . '/Fixtures/queued-collector-consumer-native.php', $mode], dirname(__DIR__, 2), ['KADUPUL_PURGE_PRIMARY_DSN' => self::$dsns['primary'], 'KADUPUL_PURGE_REMOTE_DSN' => self::$dsns['remote'], 'KADUPUL_TEST_MYSQL_USER' => self::$user, 'KADUPUL_TEST_MYSQL_PASSWORD' => self::$password], timeout: 45);
        $process->run();
        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        self::assertSame('', $process->getErrorOutput());
        $value = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(hash_file('sha256', dirname(__DIR__, 2) . '/poller_commands.php'), $value['source_sha256']);
        self::assertSame($mode, $value['mode']);
        return $value;
    }
}

/** Native server failure injection; all transaction/row operations remain PDO. */
final class QueuedPurgeFaultPdo extends PDO
{
    private bool $committed = false;

    public function commit(): bool
    {
        $result = parent::commit();
        $this->committed = $result;
        return $result;
    }

    public function prepare(string $query, array $options = []): \PDOStatement|false
    {
        if ($this->committed && str_starts_with($query, 'SET SESSION')) {
            return parent::prepare('SET SESSION queued_purge_nonexistent_option = 1', $options);
        }
        return parent::prepare($query, $options);
    }
}
