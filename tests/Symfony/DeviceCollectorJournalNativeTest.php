<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Kadupul\Inventory\Infrastructure\Legacy\DeviceCollectorCleanup;
use PDO;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/src/Platform/Infrastructure/Legacy/LegacyReferenceWriteTransaction.php';
require_once dirname(__DIR__, 2) . '/src/Inventory/Infrastructure/Legacy/DeviceCollectorReplication.php';
if (!class_exists(DeviceCollectorCleanup::class, false)) {
    require_once dirname(__DIR__, 2) . '/src/Inventory/Infrastructure/Legacy/DeviceCollectorCleanup.php';
}

final class DeviceCollectorJournalNativeTest extends TestCase
{
    private ?PDO $database = null;
    private string $schema = '';

    protected function setUp(): void
    {
        $dsn = getenv('KADUPUL_TEST_MYSQL_DSN');
        if (!$dsn) {
            self::markTestSkipped('An explicitly configured disposable MySQL/MariaDB server is required.');
        }
        $this->database = new CollectorJournalDatabase($dsn, getenv('KADUPUL_TEST_MYSQL_ADMIN_USER') ?: (getenv('KADUPUL_TEST_MYSQL_USER') ?: 'root'), getenv('KADUPUL_TEST_MYSQL_ADMIN_PASSWORD') ?: (getenv('KADUPUL_TEST_MYSQL_PASSWORD') ?: ''), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->schema = 'kadupul_cleanup_' . bin2hex(random_bytes(8));
        $this->database->exec("CREATE DATABASE `{$this->schema}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $this->database->exec("USE `{$this->schema}`");
        $this->database->exec('CREATE TABLE settings (name VARCHAR(255) PRIMARY KEY, value VARCHAR(4096) NOT NULL) ENGINE=InnoDB');
    }

    protected function tearDown(): void
    {
        if ($this->database !== null) {
            if ($this->database->inTransaction()) {
                $this->database->rollBack();
            }
            if ($this->schema !== '') {
                $this->database->exec("DROP DATABASE `{$this->schema}`");
            }
        }
    }

    public function testPendingOwnersSurviveMovesAndReturningOwnerIsCancelled(): void
    {
        $db = $this->database;
        $journal = new DeviceCollectorCleanup();
        $db->beginTransaction();
        self::assertSame([7 => [2 => 3]], $journal->retain($db, [7 => 2], 3));
        self::assertTrue($db->inTransaction());
        self::assertSame([7 => [2 => 4, 3 => 4]], $journal->retain($db, [7 => 3], 4));
        self::assertSame([7 => [2 => 4, 3 => 4]], $journal->retain($db, [7 => 4], 4));
        self::assertSame([7 => [3 => 2, 4 => 2]], $journal->retain($db, [7 => 4], 2));
        $db->commit();
        self::assertSame([7 => [3 => 2, 4 => 2]], $journal->pending($db, [7]));
        $db->beginTransaction();
        $journal->acknowledge($db, 7, 3, 2);
        self::assertSame([7 => [4 => 2]], $journal->pending($db, [7], true));
        $db->rollBack();
        self::assertSame([7 => [3 => 2, 4 => 2]], $journal->pending($db, [7]));
    }

    public function testLaterReceiptFailureRollsBackWholeUnitAndPreservesCallerWork(): void
    {
        $db = $this->database;
        $db->exec("CREATE TRIGGER reject_later BEFORE INSERT ON settings FOR EACH ROW BEGIN IF NEW.name='poller_replicate_device_cleanup_8_2' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='fixture later receipt rejection'; END IF; END");
        $db->beginTransaction();
        $db->exec("INSERT INTO settings VALUES ('unrelated','retained')");
        $failure = null;
        try {
            (new DeviceCollectorCleanup())->retain($db, [7 => 2, 8 => 2], 1);
        } catch (\PDOException $error) {
            $failure = $error;
        }
        self::assertInstanceOf(\PDOException::class, $failure);
        self::assertStringContainsString('fixture later receipt rejection', $failure->getMessage());
        self::assertTrue($db->inTransaction());
        self::assertSame([], (new DeviceCollectorCleanup())->pending($db, [7, 8], true));
        self::assertSame('retained', $db->query("SELECT value FROM settings WHERE name='unrelated'")->fetchColumn());
        $db->commit();
        self::assertSame('retained', $db->query("SELECT value FROM settings WHERE name='unrelated'")->fetchColumn());
    }

    public function testAcknowledgementRequiresExactOwnerAndBinaryReceipt(): void
    {
        $db = $this->database;
        $journal = new DeviceCollectorCleanup();
        $db->exec("INSERT INTO settings VALUES ('poller_replicate_device_cleanup_7_2','3'),('unrelated','retained')");
        $db->beginTransaction();
        $failure = null;
        try {
            $journal->acknowledge($db, 7, 2, 4);
        } catch (\RuntimeException $error) {
            $failure = $error;
        }
        self::assertInstanceOf(\RuntimeException::class, $failure);
        self::assertSame('Collector cleanup acknowledgement changed', $failure->getMessage());
        self::assertSame([7 => [2 => 3]], $journal->pending($db, [7], true));
        $db->exec("UPDATE settings SET name='POLLER_REPLICATE_DEVICE_CLEANUP_7_2' WHERE name='poller_replicate_device_cleanup_7_2'");
        $failure = null;
        try {
            $journal->acknowledge($db, 7, 2, 3);
        } catch (\RuntimeException $error) {
            $failure = $error;
        }
        self::assertInstanceOf(\RuntimeException::class, $failure);
        self::assertSame('Collector cleanup acknowledgement changed', $failure->getMessage());
        self::assertSame('3', $db->query("SELECT value FROM settings WHERE BINARY name='POLLER_REPLICATE_DEVICE_CLEANUP_7_2'")->fetchColumn());
        self::assertSame('retained', $db->query("SELECT value FROM settings WHERE name='unrelated'")->fetchColumn());
        $db->rollBack();
    }

    /** @dataProvider malformedReceipts */
    public function testMalformedPersistedReceiptFailsClosed(string $name, string $value): void
    {
        $this->database->prepare('INSERT INTO settings VALUES (?,?)')->execute([$name, $value]);
        $this->expectException(\RuntimeException::class);
        (new DeviceCollectorCleanup())->pending($this->database, [7]);
    }

    public static function malformedReceipts(): iterable
    {
        yield ['poller_replicate_device_cleanup_7_02', '1'];
        yield ['poller_replicate_device_cleanup_7_2', '01'];
        yield ['poller_replicate_device_cleanup_7_65536', '1'];
        yield ['poller_replicate_device_cleanup_7_2', '65536'];
        yield ['poller_replicate_device_cleanup_7_1', '2'];
        yield ['POLLER_REPLICATE_DEVICE_CLEANUP_7_2', '1'];
    }

    public function testNontransactionalParticipantIsRefusedBeforeReceiptWrite(): void
    {
        // MyISAM limits utf8mb4 index bytes below this installed key width.
        $this->database->exec('ALTER TABLE settings CONVERT TO CHARACTER SET latin1');
        $this->database->exec('ALTER TABLE settings ENGINE=MyISAM');
        $this->database->beginTransaction();
        $failure = null;
        try {
            (new DeviceCollectorCleanup())->retain($this->database, [7 => 2], 1);
        } catch (\RuntimeException $error) {
            $failure = $error;
        }
        self::assertInstanceOf(\RuntimeException::class, $failure);
        self::assertSame(0, (int) $this->database->query('SELECT COUNT(*) FROM settings')->fetchColumn());
        self::assertTrue($this->database->inTransaction());
    }

    public function testTemporaryShadowIsRefusedBeforeReceiptWrite(): void
    {
        $this->database->exec('CREATE TEMPORARY TABLE settings (name VARCHAR(255) PRIMARY KEY,value VARCHAR(4096)) ENGINE=InnoDB');
        $this->database->beginTransaction();
        $failure = null;
        try {
            (new DeviceCollectorCleanup())->retain($this->database, [7 => 2], 1);
        } catch (\RuntimeException $error) {
            $failure = $error;
        }
        self::assertInstanceOf(\RuntimeException::class, $failure);
        self::assertSame(0, (int) $this->database->query('SELECT COUNT(*) FROM settings')->fetchColumn());
        self::assertTrue($this->database->inTransaction());
    }

    public function testMaximumSelectionMetadataQueriesAreConstant(): void
    {
        $db = $this->database;
        $db->beginTransaction();
        $db->metadataQueries = 0;
        $owners = array_fill_keys(range(1, 100), 2);
        $result = (new DeviceCollectorCleanup())->retain($db, $owners, 1);
        self::assertCount(100, $result);
        self::assertSame(6, $db->metadataQueries);
        $db->rollBack();
        self::assertSame([], (new DeviceCollectorCleanup())->pending($db, array_keys($owners)));
    }

    public function testReceiptRecheckAfterHostWaitUsesCurrentRead(): void
    {
        $db = $this->database;
        $db->exec('CREATE TABLE cleanup_host (id MEDIUMINT UNSIGNED PRIMARY KEY,poller_id INT UNSIGNED) ENGINE=InnoDB');
        $db->exec('INSERT INTO cleanup_host VALUES (7,2)');
        $db->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $db->beginTransaction();
        $journal = new DeviceCollectorCleanup();
        self::assertSame([], $journal->pending($db, [7]));
        $connectionId = (int) $db->query('SELECT CONNECTION_ID()')->fetchColumn();
        $fixture = __DIR__ . '/collector_cleanup_lock_probe.php';
        $hash = hash_file('sha256', $fixture);
        $process = proc_open([PHP_BINARY, '-d', 'zend.exception_ignore_args=1', '-d', 'auto_prepend_file=', $fixture, $this->schema, (string) $connectionId], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        try {
            self::assertSame("READY\n", fgets($pipes[1]));
            $row = $db->query('SELECT poller_id FROM cleanup_host WHERE id=7 FOR UPDATE')->fetchColumn();
            self::assertSame(3, (int) $row);
            $proof = json_decode(stream_get_contents($pipes[1]), true, 16, JSON_THROW_ON_ERROR);
            self::assertTrue($proof['observed_host_lock_wait']);
            self::assertSame([], $journal->pending($db, [7]));
            self::assertSame([7 => [2 => 3]], $journal->pending($db, [7], true));
            self::assertSame('', stream_get_contents($pipes[2]));
        } finally {
            foreach ($pipes as $pipe) {
                fclose($pipe);
            }
            self::assertSame(0, proc_close($process));
            self::assertSame($hash, hash_file('sha256', $fixture));
        }
        $db->rollBack();
    }

    /** @dataProvider unconfirmedStages */
    public function testUnconfirmedReadWriteOrAcknowledgementPreservesCallerUnit(string $stage): void
    {
        $db = $this->database;
        $db->exec("INSERT INTO settings VALUES ('unrelated','committed')");
        if ($stage === 'ack') {
            $db->exec("INSERT INTO settings VALUES ('poller_replicate_device_cleanup_7_2','3')");
        }
        $db->setAttribute(PDO::ATTR_STATEMENT_CLASS, [CollectorJournalReceiptStatement::class, [$db]]);
        $db->beginTransaction();
        $db->exec("INSERT INTO settings VALUES ('caller_work','retained')");
        $db->failureStage = $stage;
        $failure = null;
        try {
            if ($stage === 'ack') {
                (new \Kadupul\Platform\Infrastructure\Legacy\LegacyReferenceWriteTransaction($db))->run(
                    static function () use ($db): bool {
                        (new DeviceCollectorCleanup())->acknowledge($db, 7, 2, 3);
                        return true;
                    },
                    ['settings']
                );
            } else {
                (new DeviceCollectorCleanup())->retain($db, [7 => 2], 3);
            }
        } catch (\RuntimeException $error) {
            $failure = $error;
        } finally {
            $db->failureStage = '';
        }
        self::assertInstanceOf(\RuntimeException::class, $failure);
        self::assertStringContainsString($stage === 'read' ? 'receipts unavailable' : 'receipt operation unavailable', $failure->getMessage());
        self::assertTrue($db->inTransaction());
        self::assertSame($stage === 'ack' ? [7 => [2 => 3]] : [], (new DeviceCollectorCleanup())->pending($db, [7], true));
        self::assertSame('retained', $db->query("SELECT value FROM settings WHERE name='caller_work'")->fetchColumn());
        self::assertSame('committed', $db->query("SELECT value FROM settings WHERE name='unrelated'")->fetchColumn());
        $db->rollBack();
    }

    public static function unconfirmedStages(): iterable
    {
        yield ['prepare'];
        yield ['read'];
        yield ['write'];
        yield ['ack'];
    }

    public function testUnconfirmedOwnedReadCommitCannotReturnSuccess(): void
    {
        $db = $this->database;
        $db->rejectCommit = true;
        $failure = null;
        try {
            (new DeviceCollectorCleanup())->pending($db, [7]);
        } catch (\RuntimeException $error) {
            $failure = $error;
        } finally {
            $db->rejectCommit = false;
        }
        self::assertInstanceOf(\RuntimeException::class, $failure);
        self::assertStringContainsString('commit could not be confirmed', $failure->getMessage());
        self::assertFalse($db->inTransaction());
    }

    public function testUnavailableCleanupCountCannotAcknowledgeDurableReceipt(): void
    {
        $db = $this->database;
        foreach (['host' => 'id', 'host_graph' => 'host_id', 'host_snmp_query' => 'host_id', 'host_snmp_cache' => 'host_id', 'poller_item' => 'host_id', 'poller_reindex' => 'host_id', 'graph_tree_items' => 'host_id', 'reports_items' => 'host_id', 'data_local' => 'host_id', 'graph_local' => 'host_id'] as $table => $column) {
            $db->exec("CREATE TABLE $table ($column MEDIUMINT UNSIGNED) ENGINE=InnoDB");
        }
        $db->exec('CREATE TABLE poller_command (command VARCHAR(191)) ENGINE=InnoDB');
        $db->exec("INSERT INTO settings VALUES ('poller_replicate_device_cleanup_7_2','3')");
        $db->setAttribute(PDO::ATTR_STATEMENT_CLASS, [CollectorJournalReceiptStatement::class, [$db]]);
        $db->beginTransaction();
        $db->failureStage = 'count';
        $failure = null;
        try {
            (new \Kadupul\Platform\Infrastructure\Legacy\LegacyReferenceWriteTransaction($db))->run(
                static function () use ($db): bool {
                    (new \Kadupul\Inventory\Infrastructure\Legacy\DeviceCollectorReplication())->verifyPurged($db, 7);
                    (new DeviceCollectorCleanup())->acknowledge($db, 7, 2, 3);
                    return true;
                },
                ['settings']
            );
        } catch (\RuntimeException $error) {
            $failure = $error;
        } finally {
            $db->failureStage = '';
        }
        self::assertInstanceOf(\RuntimeException::class, $failure);
        self::assertSame('Previous collector cleanup could not be confirmed', $failure->getMessage());
        self::assertTrue($db->inTransaction());
        self::assertSame([7 => [2 => 3]], (new DeviceCollectorCleanup())->pending($db, [7], true));
        $db->commit();
        self::assertSame([7 => [2 => 3]], (new DeviceCollectorCleanup())->pending($db, [7]));
    }
}

final class CollectorJournalDatabase extends PDO
{
    public int $metadataQueries = 0;
    public string $failureStage = '';
    public bool $rejectCommit = false;

    public function prepare(string $query, array $options = []): \PDOStatement|false
    {
        $result = parent::prepare($query, $options);
        return $this->failureStage === 'prepare' && str_starts_with($query, 'SELECT name, value FROM settings') ? false : $result;
    }

    public function commit(): bool
    {
        return $this->rejectCommit ? false : parent::commit();
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$arguments): \PDOStatement|false
    {
        if (str_starts_with($query, 'SHOW ')) {
            $this->metadataQueries++;
        }
        return $fetchMode === null ? parent::query($query) : parent::query($query, $fetchMode, ...$arguments);
    }
}

/** Failure receipts follow real native execution/fetch, including successful writes. */
final class CollectorJournalReceiptStatement extends \PDOStatement
{
    private bool $executed = false;
    private bool $fetched = false;

    protected function __construct(private CollectorJournalDatabase $database) {}

    public function execute(?array $parameters = null): bool
    {
        $result = parent::execute($parameters);
        $this->executed = true;
        return $result;
    }

    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$arguments): array
    {
        $result = parent::fetchAll($mode, ...$arguments);
        $this->fetched = true;
        return $result;
    }

    public function fetchColumn(int $column = 0): mixed
    {
        $result = parent::fetchColumn($column);
        return $this->database->failureStage === 'count' && str_starts_with($this->queryString, 'SELECT COUNT(*) FROM host WHERE') ? false : $result;
    }

    public function errorCode(): ?string
    {
        $stage = $this->database->failureStage;
        if (($stage === 'read' && $this->fetched && str_starts_with($this->queryString, 'SELECT name, value FROM settings'))
            || ($stage === 'write' && $this->executed && str_starts_with($this->queryString, 'INSERT INTO settings'))
            || ($stage === 'ack' && $this->executed && str_starts_with($this->queryString, 'DELETE FROM settings'))) {
            return 'HY000';
        }
        return parent::errorCode();
    }
}
