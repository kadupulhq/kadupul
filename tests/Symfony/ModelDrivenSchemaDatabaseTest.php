<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

use Kadupul\Platform\Domain\Schema\AuditSchemaDump;
use Kadupul\Platform\Domain\Schema\AuditTableStatus;
use Kadupul\Platform\Domain\Schema\LiveTable;
use Kadupul\Platform\Domain\Schema\PluginSchemaChanges;
use Kadupul\Platform\Domain\Schema\TableAudit;
use Kadupul\Platform\Domain\Schema\TableStatus;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * The 1.2.37 step on a real server: it creates what cacti.sql creates, the
 * audit finds nothing to repair in either, and a failure stops the step so
 * the runner keeps the previous version.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class ModelDrivenSchemaDatabaseTest extends TestCase
{
    private const array TABLES = ['kadupul_metric_binding', 'kadupul_writer_ownership'];

    private PDO $db;

    protected function setUp(): void
    {
        $dsn = getenv('KADUPUL_TEST_MYSQL_DSN');
        if ($dsn === false || $dsn === '') {
            self::markTestSkipped('Set KADUPUL_TEST_MYSQL_DSN to a task-owned native database; the 1.2.37 upgrade was not run.');
        }
        $this->db = new class ($dsn, getenv('KADUPUL_TEST_MYSQL_USER') ?: 'root', getenv('KADUPUL_TEST_MYSQL_PASSWORD') ?: '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_SILENT]) extends PDO {
            public bool $failCreate = false;

            public function prepare(string $query, array $options = []): PDOStatement|false
            {
                if ($this->failCreate && str_starts_with($query, 'CREATE TABLE')) {
                    $query = 'CREATE TABLE kadupul_upgrade_absent.denied (x int)';
                }

                return parent::prepare($query, $options);
            }
        };
        $root = dirname(__DIR__, 2);
        require $root . '/include/global_constants.php';
        require __DIR__ . '/Fixtures/forward_schema_repair_bridge.php';
        require $root . '/install/upgrades/1_2_37.php';
        $GLOBALS['forward_schema_pdo'] = $this->db;
        $GLOBALS['forward_schema_status'] = [];
        $this->drop();
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            $this->db->failCreate = false;
            $this->drop();
        }
    }

    public function testTheUpgradeCreatesWhatAFreshInstallCreatesAndTheAuditAcceptsBoth(): void
    {
        $sql = (string) file_get_contents(dirname(__DIR__, 2) . '/cacti.sql');
        foreach (self::TABLES as $table) {
            self::assertSame(1, preg_match('/^CREATE TABLE ' . $table . ' \(.*?^\) [^;\n]*;/ms', $sql, $create));
            $this->execute(rtrim($create[0], ';'));
        }
        $fresh = $this->definitions();
        $this->drop();

        upgrade_to_1_2_37();

        self::assertNotContains(DB_STATUS_ERROR, $GLOBALS['forward_schema_status']);
        self::assertSame($fresh, $this->definitions());
        $baseline = AuditSchemaDump::parse((string) file_get_contents(dirname(__DIR__, 2) . '/docs/audit_schema.sql'));
        foreach (self::TABLES as $table) {
            $audit = TableAudit::of($this->live($table), $baseline, PluginSchemaChanges::none(), true);
            self::assertSame(AuditTableStatus::Audited, $audit->status);
            self::assertSame([[], 0, 0, []], [$audit->findings, $audit->errors, $audit->warnings, $audit->clauses], $table);
        }
    }

    public function testARerunKeepsTheTablesAndTheirRows(): void
    {
        upgrade_to_1_2_37();
        $this->execute("INSERT INTO kadupul_writer_ownership VALUES (9, 'traffic_in', 'model_driven', '2026-10-23 00:00:00')");

        upgrade_to_1_2_37();

        self::assertNotContains(DB_STATUS_ERROR, $GLOBALS['forward_schema_status']);
        self::assertSame('1', (string) $this->db->query('SELECT COUNT(*) FROM kadupul_writer_ownership')->fetchColumn());
    }

    public function testAnExistingTableOfAnotherShapeStopsTheStepWithoutTouchingIt(): void
    {
        $this->execute('CREATE TABLE kadupul_writer_ownership (local_data_id int unsigned NOT NULL PRIMARY KEY, writer varchar(32) NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        $this->execute("INSERT INTO kadupul_writer_ownership VALUES (9, 'operator')");

        try {
            upgrade_to_1_2_37();
            self::fail('The step accepted a table it did not define.');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('kadupul_writer_ownership is missing or differs', $e->getMessage());
        }
        self::assertSame([['9', 'operator']], array_map(static fn(array $row): array => array_map('strval', $row), $this->db->query('SELECT * FROM kadupul_writer_ownership')->fetchAll(PDO::FETCH_NUM)));
    }

    public function testAFailedCreateStopsTheStepAndARetrySucceeds(): void
    {
        $this->db->failCreate = true;
        try {
            upgrade_to_1_2_37();
            self::fail('The step continued after its CREATE TABLE failed.');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('kadupul_metric_binding is missing', $e->getMessage());
        }
        self::assertContains(DB_STATUS_ERROR, $GLOBALS['forward_schema_status']);
        self::assertFalse($this->db->query("SHOW TABLES LIKE 'kadupul\\_%'")->fetchColumn());

        $this->db->failCreate = false;
        $GLOBALS['forward_schema_status'] = [];
        upgrade_to_1_2_37();
        self::assertNotContains(DB_STATUS_ERROR, $GLOBALS['forward_schema_status']);
        self::assertCount(2, $this->definitions());
    }

    /** @return array<string, string> SHOW CREATE TABLE by table */
    private function definitions(): array
    {
        $found = [];
        foreach (self::TABLES as $table) {
            $statement = $this->db->query('SHOW CREATE TABLE ' . $table);
            self::assertNotFalse($statement, $table . ' was not created');
            $found[$table] = (string) $statement->fetch(PDO::FETCH_NUM)[1];
        }

        return $found;
    }

    private function live(string $table): LiveTable
    {
        $status = $this->db->query("SHOW TABLE STATUS LIKE '" . $table . "'")->fetch(PDO::FETCH_ASSOC);
        $pick = static fn(array $row, array $fields): array => array_map(static fn(string $f): ?string => $row[$f] === null ? null : (string) $row[$f], array_combine($fields, $fields));

        return new LiveTable(
            $table,
            new TableStatus($status['Engine'], $status['Collation'], $status['Row_format'], (int) $status['Rows']),
            array_map(static fn(array $row): array => $pick($row, ['Field', 'Type', 'Collation', 'Null', 'Key', 'Default', 'Extra']), $this->db->query('SHOW FULL COLUMNS FROM ' . $table)->fetchAll(PDO::FETCH_ASSOC)),
            array_map(static fn(array $row): array => $pick($row, ['Table', 'Non_unique', 'Key_name', 'Seq_in_index', 'Column_name', 'Collation', 'Cardinality', 'Sub_part', 'Packed', 'Null', 'Index_type', 'Comment']), $this->db->query('SHOW INDEXES FROM ' . $table)->fetchAll(PDO::FETCH_ASSOC)),
        );
    }

    private function execute(string $sql): void
    {
        self::assertNotFalse($this->db->exec($sql), 'Fixture SQL failed: ' . $sql);
    }

    private function drop(): void
    {
        foreach (self::TABLES as $table) {
            $this->db->exec('DROP TABLE IF EXISTS ' . $table);
        }
    }
}
