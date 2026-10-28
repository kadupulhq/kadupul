<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Schema\Schema;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Bridge\Doctrine\Transport\Connection;

/**
 * The 1.2.38 step: run-scoped Boost partition status and the Messenger
 * transport table. The database tests need KADUPUL_TEST_MYSQL_DSN.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class BoostSchemaUpgradeTest extends TestCase
{
    private const string QUEUE = 'kadupul_messenger_messages';
    private const string STATUS = 'poller_output_boost_processes';

    private ?PDO $db = null;

    protected function setUp(): void
    {
        $root = dirname(__DIR__, 2);
        require_once $root . '/include/global_constants.php';
        require_once __DIR__ . '/Fixtures/forward_schema_repair_bridge.php';
        require_once $root . '/install/upgrades/1_2_38.php';
        $dsn = getenv('KADUPUL_TEST_MYSQL_DSN');
        if ($dsn === false || $dsn === '') {
            return;
        }
        $this->db = new PDO($dsn, getenv('KADUPUL_TEST_MYSQL_USER') ?: 'root', getenv('KADUPUL_TEST_MYSQL_PASSWORD') ?: '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_SILENT]);
        $GLOBALS['forward_schema_pdo'] = $this->db;
        $GLOBALS['forward_schema_status'] = [];
        $this->drop();
    }

    protected function tearDown(): void
    {
        if ($this->db !== null) {
            $this->drop();
        }
    }

    public function testUpgradeAddsRunScopedStatusAndQueueTable(): void
    {
        $db = $this->database();
        $db->exec('CREATE TABLE ' . self::STATUS . ' (sock_int_value bigint(20) unsigned NOT NULL auto_increment, status varchar(255) default NULL, PRIMARY KEY (sock_int_value)) ENGINE=MEMORY');
        $db->exec("INSERT INTO " . self::STATUS . " (status) VALUES ('5')");

        upgrade_to_1_2_38();

        self::assertNotContains(DB_STATUS_ERROR, $GLOBALS['forward_schema_status']);
        self::assertSame('sock_int_value,run_id,process_handler,status', $this->columns(self::STATUS));
        self::assertSame(['run_id', 'process_handler'], $this->indexColumns(self::STATUS, 'run_partition', 0));
        self::assertSame('MEMORY', $this->engine(self::STATUS));
        self::assertSame('id,body,headers,queue_name,created_at,available_at,delivered_at', $this->columns(self::QUEUE));
        self::assertSame('InnoDB', $this->engine(self::QUEUE));

        $db->exec("INSERT INTO " . self::STATUS . " (run_id, process_handler, status) VALUES ('" . str_repeat('a', 32) . "', 1, '7')");
        $upsert = $db->prepare('INSERT INTO ' . self::STATUS . ' (run_id, process_handler, status) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE status = VALUES(status)');
        self::assertTrue($upsert->execute([str_repeat('a', 32), 1, '9']));
        self::assertSame('1', (string) $db->query('SELECT COUNT(*) FROM ' . self::STATUS)->fetchColumn());
        self::assertSame('9', (string) $db->query('SELECT status FROM ' . self::STATUS)->fetchColumn());

        $GLOBALS['forward_schema_status'] = [];
        upgrade_to_1_2_38();
        self::assertNotContains(DB_STATUS_ERROR, $GLOBALS['forward_schema_status']);
        self::assertSame('1', (string) $db->query('SELECT COUNT(*) FROM ' . self::STATUS)->fetchColumn(), 'a rerun keeps the rows');
    }

    public function testFreshInstallShapeNeedsNoAlter(): void
    {
        $sql = (string) file_get_contents(dirname(__DIR__, 2) . '/cacti.sql');
        foreach ([self::STATUS, self::QUEUE] as $table) {
            $this->database()->exec(self::freshInstall($sql, $table));
        }

        upgrade_to_1_2_38();

        self::assertNotContains(DB_STATUS_ERROR, $GLOBALS['forward_schema_status']);
        self::assertSame('sock_int_value,run_id,process_handler,status', $this->columns(self::STATUS));
    }

    public function testAQueueTableOfAnotherShapeStopsTheStepWithoutTouchingIt(): void
    {
        $db = $this->database();
        $db->exec('CREATE TABLE ' . self::QUEUE . ' (id bigint NOT NULL PRIMARY KEY, body text NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        $db->exec("INSERT INTO " . self::QUEUE . " VALUES (1, 'x')");

        try {
            upgrade_to_1_2_38();
            self::fail('The step accepted a table it did not define.');
        } catch (RuntimeException $e) {
            self::assertStringContainsString(self::QUEUE . ' is missing or differs', $e->getMessage());
        }
        self::assertSame('1', (string) $db->query('SELECT COUNT(*) FROM ' . self::QUEUE)->fetchColumn());
    }

    public function testAQueueTableWithAnotherCollationStopsTheStep(): void
    {
        $db = $this->database();
        $db->exec('CREATE TABLE ' . self::QUEUE . ' (id bigint NOT NULL auto_increment, body longtext NOT NULL, headers longtext NOT NULL, queue_name varchar(190) NOT NULL, created_at datetime NOT NULL, available_at datetime NOT NULL, delivered_at datetime default NULL, PRIMARY KEY (id), KEY IDX_7CB5BDEEFB7336F0E3BD61CE16BA31DBBF396750 (queue_name, available_at, delivered_at, id)) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci');

        try {
            upgrade_to_1_2_38();
            self::fail('The step accepted a queue table with another collation.');
        } catch (RuntimeException $e) {
            self::assertStringContainsString(self::QUEUE . ' is missing or differs', $e->getMessage());
        }
    }

    public function testARetryAfterColumnsWereAddedWithoutTheKeyTruncatesStaleRows(): void
    {
        $db = $this->database();
        $db->exec('CREATE TABLE ' . self::STATUS . ' (sock_int_value bigint(20) unsigned NOT NULL auto_increment, run_id char(32) NOT NULL default \'\', process_handler int(10) unsigned NOT NULL default 0, status varchar(255) default NULL, PRIMARY KEY (sock_int_value)) ENGINE=MEMORY');
        $db->exec("INSERT INTO " . self::STATUS . " (status) VALUES ('1'), ('2')");

        upgrade_to_1_2_38();

        self::assertNotContains(DB_STATUS_ERROR, $GLOBALS['forward_schema_status']);
        self::assertSame(['run_id', 'process_handler'], $this->indexColumns(self::STATUS, 'run_partition', 0));
        self::assertSame('0', (string) $db->query('SELECT COUNT(*) FROM ' . self::STATUS)->fetchColumn());
    }

    public function testMasterCountsOnlyTheCurrentRun(): void
    {
        $db = $this->database();
        upgrade_to_1_2_38();
        $current = str_repeat('a', 32);
        $earlier = str_repeat('b', 32);
        $insert = $db->prepare('INSERT INTO ' . self::STATUS . ' (run_id, process_handler, status) VALUES (?, ?, ?)');
        foreach ([[$current, 1, '4'], [$current, 2, '6'], [$earlier, 1, '-1'], [$earlier, 2, '100'], [$earlier, 3, '100']] as $row) {
            self::assertTrue($insert->execute($row));
        }

        // The statements poller_boost.php runs after the children finish.
        $failed = $this->scalar('SELECT COUNT(*) FROM poller_output_boost_processes WHERE run_id = ? AND status < 0', $current);
        $updates = $this->scalar('SELECT SUM(status) FROM poller_output_boost_processes WHERE run_id = ?', $current);
        $reported = $this->scalar('SELECT COUNT(*) FROM poller_output_boost_processes WHERE run_id = ?', $current);

        self::assertSame('0', $failed);
        self::assertSame('10', $updates);
        self::assertSame('2', $reported);
        self::assertSame('1', $this->scalar('SELECT COUNT(*) FROM poller_output_boost_processes WHERE run_id = ? AND status < 0', $earlier));
    }

    public function testFreshInstallAndUpgradeCreateTheSameTables(): void
    {
        $sql = (string) file_get_contents(dirname(__DIR__, 2) . '/cacti.sql');
        foreach (upgrade_boost_queue_tables() as $table => $definition) {
            self::assertStringStartsWith('CREATE TABLE IF NOT EXISTS ' . $table . ' (', $definition['create']);
            self::assertSame(self::normalized(self::freshInstall($sql, $table)), self::normalized($definition['create']), $table);
        }
    }

    public function testQueueTableMatchesTheLockedTransportSchema(): void
    {
        $connection = new Connection(['table_name' => self::QUEUE], DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]));
        $add = new ReflectionMethod($connection, 'addTableToSchema');
        $statements = (new MariaDBPlatform())->getCreateTablesSQL($add->invoke($connection, new Schema())->getTables());
        self::assertCount(1, $statements);

        $definition = upgrade_boost_queue_tables()[self::QUEUE]['create'];
        self::assertCount(7, self::columnDefinitions($statements[0]));
        self::assertSame(self::columnDefinitions($statements[0]), self::columnDefinitions($definition));
        self::assertSame(self::indexOf($statements[0]), self::indexOf($definition), 'the index name is derived from the table name and must match the transport');
    }

    private function database(): PDO
    {
        if ($this->db === null) {
            self::markTestSkipped('Set KADUPUL_TEST_MYSQL_DSN to a task-owned database; the 1.2.38 upgrade was not run.');
        }

        return $this->db;
    }

    private function columns(string $table): string
    {
        return (string) $this->database()->query("SELECT GROUP_CONCAT(COLUMN_NAME ORDER BY ORDINAL_POSITION) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '" . $table . "'")->fetchColumn();
    }

    private function scalar(string $sql, string $run): string
    {
        $statement = $this->database()->prepare($sql);
        $statement->execute([$run]);

        return (string) $statement->fetchColumn();
    }

    private function engine(string $table): string
    {
        return (string) $this->database()->query("SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '" . $table . "'")->fetchColumn();
    }

    /** @return list<string> */
    private function indexColumns(string $table, string $index, int $nonUnique): array
    {
        $rows = $this->database()->query("SELECT COLUMN_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '" . $table . "' AND INDEX_NAME = '" . $index . "' AND NON_UNIQUE = " . $nonUnique . ' ORDER BY SEQ_IN_INDEX');

        return array_map('strval', $rows->fetchAll(PDO::FETCH_COLUMN));
    }

    private function drop(): void
    {
        foreach ([self::STATUS, self::QUEUE] as $table) {
            $this->db?->exec('DROP TABLE IF EXISTS ' . $table);
        }
    }

    private static function freshInstall(string $sql, string $table): string
    {
        self::assertSame(1, preg_match('/^CREATE TABLE `?' . $table . '`? \(.*?^\)[^;\n]*;/ms', $sql, $found), $table . ' must be created once in cacti.sql');

        return rtrim($found[0], ';');
    }

    private static function normalized(string $create): string
    {
        return (string) preg_replace('/\s+/', ' ', str_replace(['CREATE TABLE IF NOT EXISTS ', '`'], ['CREATE TABLE ', ''], $create));
    }

    /** @return list<string> each column with its modifiers sorted and case folded */
    private static function columnDefinitions(string $create): array
    {
        preg_match_all('/(?:\(|,|^)\s*(id|body|headers|queue_name|created_at|available_at|delivered_at) ([^,]+?)(?=,\s*(?:[a-z_]+ [a-z]|PRIMARY|INDEX|KEY)|\s*\)\s*(?:ENGINE|$)|,\s*(?:INDEX|KEY|PRIMARY)|\n)/im', $create, $found, PREG_SET_ORDER);
        $columns = [];
        foreach ($found as $match) {
            $words = preg_split('/\s+/', strtolower(trim($match[2])), -1, PREG_SPLIT_NO_EMPTY);
            $type = array_shift($words);
            sort($words);
            $columns[] = strtolower($match[1]) . ' ' . $type . ' ' . implode(' ', $words);
        }

        return $columns;
    }

    private static function indexOf(string $create): string
    {
        self::assertSame(1, preg_match('/(?:INDEX|KEY) (IDX_\w+) \(([^)]+)\)/', $create, $found));

        return $found[1] . ' (' . $found[2] . ')';
    }
}
