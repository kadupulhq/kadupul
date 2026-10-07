<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class ForwardSchemaRepairTest extends TestCase
{
    private PDO $db;
    private array $tables = ['data_input_data', 'aggregate_graphs'];

    protected function setUp(): void
    {
        $dsn = getenv('KADUPUL_TEST_MYSQL_DSN');
        if ($dsn === false || $dsn === '') {
            self::markTestSkipped('Set KADUPUL_TEST_MYSQL_DSN to a task-owned native database.');
        }
        $this->db = new class ($dsn, getenv('KADUPUL_TEST_MYSQL_USER') ?: 'root', getenv('KADUPUL_TEST_MYSQL_PASSWORD') ?: '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_SILENT]) extends PDO {
            public string $fault = '';
            public int $ddl = 0;
            public function prepare(string $query, array $options = []): PDOStatement|false
            {
                if (str_starts_with($query, 'ALTER TABLE')) {
                    ++$this->ddl;
                    if ($this->fault === 'first DDL' || ($this->fault === 'second DDL' && str_contains($query, 'aggregate_graphs'))) {
                        $this->fault = '';
                        $query = 'ALTER TABLE kadupul_forward_absent ADD x int';
                    }
                }
                return parent::prepare($query, $options);
            }
            public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
            {
                if ($this->fault === 'initial metadata'
                    || ($this->fault === 'index readback' && $this->ddl === 1 && str_starts_with($query, 'SHOW INDEXES'))
                    || ($this->fault === 'timestamp readback' && $this->ddl === 2 && str_starts_with($query, 'SHOW FULL COLUMNS'))) {
                    $this->fault = '';
                    $query = 'SHOW FULL COLUMNS FROM kadupul_forward_absent';
                }
                return parent::query($query, $fetchMode, ...$fetchModeArgs);
            }
        };
        $root = dirname(__DIR__, 2);
        require $root . '/include/global_constants.php';
        require __DIR__ . '/Fixtures/forward_schema_repair_bridge.php';
        require $root . '/lib/schema_repair_integrity.php';
        $GLOBALS['database_hostname'] = 'forward-fixture';
        $GLOBALS['database_port'] = 0;
        $GLOBALS['database_default'] = 'forward-fixture';
        $GLOBALS['database_sessions'] = ['forward-fixture:0:forward-fixture' => $this->db];
        $GLOBALS['forward_schema_pdo'] = $this->db;
        $GLOBALS['forward_schema_status'] = [];
        $this->execute('CREATE TABLE data_input_data (data_input_field_id mediumint unsigned NOT NULL DEFAULT 0, data_template_data_id int unsigned NOT NULL DEFAULT 0, PRIMARY KEY(data_input_field_id,data_template_data_id)) ENGINE=InnoDB');
        $this->execute('CREATE TABLE aggregate_graphs (id int PRIMARY KEY, title_format varchar(128) NOT NULL, created timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) ENGINE=InnoDB');
        $this->execute("INSERT INTO aggregate_graphs VALUES (1,'old','2001-01-01 00:00:00')");
    }

    protected function tearDown(): void
    {
        if (!isset($this->db)) {
            return;
        }
        $this->db->fault = '';
        if ($this->db->inTransaction()) {
            $this->db->rollBack();
        }
        foreach (array_reverse($this->tables) as $table) {
            $this->db->exec('DROP TABLE IF EXISTS `' . $table . '`');
        }
    }

    private function execute(string $sql): void
    {
        self::assertNotFalse($this->db->exec($sql), 'Actual native fixture SQL failed.');
    }

    private function assertRepaired(): void
    {
        $indexes = $this->db->query('SHOW INDEXES FROM data_input_data')->fetchAll(PDO::FETCH_ASSOC);
        $index = array_values(array_filter($indexes, static fn(array $row): bool => $row['Key_name'] === 'data_input_field_id'));
        self::assertCount(1, $index);
        self::assertSame('data_input_field_id', $index[0]['Column_name']);
        self::assertSame('BTREE', $index[0]['Index_type']);
        self::assertSame(1, (int) $index[0]['Non_unique']);
        self::assertNull($index[0]['Sub_part']);
        $this->execute("UPDATE aggregate_graphs SET title_format='changed' WHERE id=1");
        self::assertSame('2001-01-01 00:00:00', $this->db->query('SELECT created FROM aggregate_graphs')->fetchColumn());
    }

    public function testRealMissingIndexAndTimestampRepairIsIdempotentAndPreservesCreatedData(): void
    {
        self::assertTrue(schema_repair_integrity());
        self::assertSame(2, $this->db->ddl);
        $this->assertRepaired();
        self::assertSame([DB_STATUS_SUCCESS, DB_STATUS_SUCCESS], $GLOBALS['forward_schema_status']);
        self::assertTrue(schema_repair_integrity());
        self::assertSame(2, $this->db->ddl, 'An already repaired schema must perform no DDL.');
    }

    public static function failures(): iterable
    {
        foreach (['initial metadata', 'first DDL', 'second DDL', 'index readback', 'timestamp readback'] as $failure) {
            yield $failure => [$failure];
        }
    }

    #[DataProvider('failures')]
    public function testRealDatabaseFailuresRemainVisibleAndRetryable(string $failure): void
    {
        $this->db->fault = $failure;
        self::assertFalse(schema_repair_integrity());
        self::assertContains(DB_STATUS_ERROR, $GLOBALS['forward_schema_status']);
        self::assertSame('2001-01-01 00:00:00', $this->db->query('SELECT created FROM aggregate_graphs')->fetchColumn());
        $indexes = $this->db->query('SHOW INDEXES FROM data_input_data')->fetchAll(PDO::FETCH_ASSOC);
        $present = array_any($indexes, static fn(array $row): bool => $row['Key_name'] === 'data_input_field_id');
        self::assertSame(!in_array($failure, ['initial metadata', 'first DDL'], true), $present, 'DDL already committed before later failure must remain visible.');
        $GLOBALS['forward_schema_status'] = [];
        self::assertTrue(schema_repair_integrity());
        self::assertNotContains(DB_STATUS_ERROR, $GLOBALS['forward_schema_status']);
        $this->assertRepaired();
    }

    public function testIncompatibleIndexIsRefusedBeforeEitherMutation(): void
    {
        $this->execute('CREATE UNIQUE INDEX data_input_field_id ON data_input_data(data_input_field_id)');
        self::assertFalse(schema_repair_integrity());
        self::assertSame(0, $this->db->ddl);
        self::assertContains(DB_STATUS_ERROR, $GLOBALS['forward_schema_status']);
        self::assertStringContainsString('on update', strtolower($this->db->query("SHOW FULL COLUMNS FROM aggregate_graphs WHERE Field='created'")->fetch(PDO::FETCH_ASSOC)['Extra']));
    }

    public function testCallerTransactionAndItsWorkArePreservedWithoutDdl(): void
    {
        self::assertTrue($this->db->beginTransaction());
        $this->execute("INSERT INTO aggregate_graphs VALUES (2,'preserve','2001-01-01 00:00:00')");
        self::assertFalse(schema_repair_integrity());
        self::assertTrue($this->db->inTransaction());
        self::assertSame(0, $this->db->ddl);
        self::assertSame('preserve', $this->db->query('SELECT title_format FROM aggregate_graphs WHERE id=2')->fetchColumn());
    }

    public function testTemporaryTableShadowIsRefusedWithoutTouchingEitherPersistentTable(): void
    {
        $this->execute('CREATE TEMPORARY TABLE data_input_data (data_input_field_id int) ENGINE=InnoDB');
        $this->tables[] = 'data_input_data';
        self::assertFalse(schema_repair_integrity());
        self::assertSame(0, $this->db->ddl);
        self::assertContains(DB_STATUS_ERROR, $GLOBALS['forward_schema_status']);
        $this->execute('DROP TEMPORARY TABLE data_input_data');
        self::assertSame(2, count($this->db->query('SHOW FULL COLUMNS FROM data_input_data')->fetchAll(PDO::FETCH_ASSOC)));
        self::assertStringContainsString('on update', strtolower($this->db->query("SHOW FULL COLUMNS FROM aggregate_graphs WHERE Field='created'")->fetch(PDO::FETCH_ASSOC)['Extra']));
    }

    public function testUnsupportedTimestampAttributesRequireManualReviewBeforeAnyDdl(): void
    {
        $this->execute("ALTER TABLE aggregate_graphs MODIFY created timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'operator metadata'");
        self::assertFalse(schema_repair_integrity());
        self::assertSame(0, $this->db->ddl);
        self::assertContains(DB_STATUS_ERROR, $GLOBALS['forward_schema_status']);
        self::assertSame('operator metadata', $this->db->query("SHOW FULL COLUMNS FROM aggregate_graphs WHERE Field='created'")->fetch(PDO::FETCH_ASSOC)['Comment']);
    }

    public function testWholeHistoricalAndForwardMigrationShareTheVerifiedRepair(): void
    {
        $schemas = [
            'automation_devices' => '(snmp_priv_protocol char(4))',
            'automation_snmp_items' => '(snmp_priv_protocol char(4))',
            'snmpagent_managers' => '(snmp_priv_protocol char(4) NOT NULL)',
            'settings' => '(name varchar(128) PRIMARY KEY, value varchar(255))',
            'settings_user' => '(name varchar(128))',
            'snmp_query_graph' => '(snmp_query_id int, graph_template_id int)',
            'user_auth_row_cache' => '(class varchar(20), time timestamp)',
            'user_domains_ldap' => '(encryption int)',
        ];
        foreach ($schemas as $table => $schema) {
            $this->tables[] = $table;
            $this->execute('CREATE TABLE `' . $table . '` ' . $schema . ' ENGINE=InnoDB');
        }
        $this->tables[] = 'poller_output_rejected';
        $root = dirname(__DIR__, 2);
        require $root . '/install/upgrades/1_2_31.php';
        require $root . '/install/upgrades/1_2_35.php';
        upgrade_to_1_2_31();
        self::assertNotContains(DB_STATUS_ERROR, $GLOBALS['forward_schema_status']);
        $this->assertRepaired();
        self::assertNotFalse($this->db->query('SELECT * FROM poller_output_rejected'));
        $before = $this->db->ddl;
        upgrade_to_1_2_35();
        self::assertSame($before, $this->db->ddl, 'The forward migration must share the already completed repair.');
        self::assertNotContains(DB_STATUS_ERROR, $GLOBALS['forward_schema_status']);
    }
}
