<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Kadupul\Tests\AssetManagement;

use Doctrine\DBAL\Tools\DsnParser;
use Kadupul\Platform\Domain\Schema\AuditSchemaDump;
use Kadupul\Platform\Domain\Schema\AuditTableStatus;
use Kadupul\Platform\Domain\Schema\LiveTable;
use Kadupul\Platform\Domain\Schema\PluginSchemaChanges;
use Kadupul\Platform\Domain\Schema\TableAudit;
use Kadupul\Platform\Domain\Schema\TableStatus;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class AssetUpgradeTest extends TestCase
{
    private \PDO $db;

    protected function setUp(): void
    {
        $dsn = getenv('KADUPUL_ASSET_TEST_DSN');
        if ($dsn === false || $dsn === '') {
            $this->markTestSkipped('Requires the disposable native asset database.');
        }
        $params = (new DsnParser())->parse($dsn);
        $params['password'] = getenv('KADUPUL_ASSET_TEST_PASSWORD') ?: ($params['password'] ?? '');
        if (!str_ends_with($params['dbname'] ?? '', '_assets_test')) {
            throw new \RuntimeException('Asset test database required.');
        }
        $this->db = new \PDO('mysql:host=' . $params['host'] . ';port=' . $params['port'] . ';dbname=' . $params['dbname'] . ';charset=utf8mb4', $params['user'], $params['password'] ?? '', [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        foreach (['asset_registry', 'asset_record', 'asset_event', 'asset_observation'] as $table) {
            $this->db->exec('DROP TABLE IF EXISTS ' . $table);
        }
        require dirname(__DIR__, 3) . '/include/global_constants.php';
        require dirname(__DIR__) . '/Fixtures/forward_schema_repair_bridge.php';
        require dirname(__DIR__, 3) . '/install/upgrades/1_2_40.php';
        $GLOBALS['forward_schema_pdo'] = $this->db;
        $GLOBALS['forward_schema_status'] = [];
    }

    public function testFreshAndUpgradedSchemasMatchAndRetryPreservesRecords(): void
    {
        $sql = file_get_contents(dirname(__DIR__, 3) . '/cacti.sql');
        $this->assertIsString($sql);
        $fresh = [];
        foreach (['asset_registry', 'asset_record', 'asset_event', 'asset_observation'] as $table) {
            $this->assertSame(1, preg_match('/CREATE TABLE IF NOT EXISTS `' . $table . '` \(.*?\) ENGINE=InnoDB[^;]+;/s', $sql, $match));
            $this->db->exec($match[0]);
            $fresh[$table] = $this->db->query('SHOW CREATE TABLE ' . $table)->fetch(\PDO::FETCH_NUM)[1];
            $this->db->exec('DROP TABLE ' . $table);
        }
        \upgrade_to_1_2_40();
        foreach ($fresh as $table => $definition) {
            $this->assertSame($definition, $this->db->query('SHOW CREATE TABLE ' . $table)->fetch(\PDO::FETCH_NUM)[1]);
        }
        $this->db->exec("UPDATE asset_registry SET revision=7,policy='{\"scope\":\"retained\"}' WHERE id=1");
        \upgrade_to_1_2_40();
        $this->assertSame(7, (int) $this->db->query('SELECT revision FROM asset_registry WHERE id=1')->fetchColumn());
        $this->assertStringContainsString('retained', $this->db->query('SELECT policy FROM asset_registry WHERE id=1')->fetchColumn());
        $baseline = AuditSchemaDump::parse(file_get_contents(dirname(__DIR__, 3) . '/docs/audit_schema.sql'));
        foreach (array_keys($fresh) as $table) {
            $audit = TableAudit::of($this->live($table), $baseline, PluginSchemaChanges::none(), true);
            $this->assertSame(AuditTableStatus::Audited, $audit->status);
            $this->assertSame([[], 0, 0, []], [$audit->findings, $audit->errors, $audit->warnings, $audit->clauses], $table);
        }
        $root = dirname(__DIR__, 3);
        $this->assertSame('1.2.40', trim(file_get_contents($root . '/include/cacti_version')));
        $this->assertMatchesRegularExpression("/^\\s*'1\\.2\\.40'\\s*=>\\s*'0103',\\n\\);/m", file_get_contents($root . '/include/global_arrays.php'));
        $this->assertNotContains(DB_STATUS_ERROR, $GLOBALS['forward_schema_status']);
    }

    public function testPartialSchemaIsRefusedAndCanBeRepairedAndRetried(): void
    {
        $this->db->exec('CREATE TABLE asset_registry (id INT UNSIGNED PRIMARY KEY, revision INT UNSIGNED NOT NULL, policy LONGTEXT NOT NULL, sources LONGTEXT NOT NULL) ENGINE=MyISAM');
        try {
            \upgrade_to_1_2_40();
            $this->fail('Nontransactional storage must be refused.');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('storage differs', $error->getMessage());
        }
        $this->assertSame(0, (int) $this->db->query('SELECT COUNT(*) FROM asset_registry')->fetchColumn());
        $this->db->exec('ALTER TABLE asset_registry ENGINE=InnoDB, CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_bin');
        \upgrade_to_1_2_40();
        $this->assertSame(1, (int) $this->db->query('SELECT COUNT(*) FROM asset_registry')->fetchColumn());
    }

    private function live(string $table): LiveTable
    {
        $status = $this->db->query("SHOW TABLE STATUS LIKE '" . $table . "'")->fetch(\PDO::FETCH_ASSOC);
        $pick = static fn(array $row, array $fields): array => array_map(static fn(string $f): ?string => $row[$f] === null ? null : (string) $row[$f], array_combine($fields, $fields));
        return new LiveTable(
            $table,
            new TableStatus($status['Engine'], $status['Collation'], $status['Row_format'], (int) $status['Rows']),
            array_map(static fn(array $row): array => $pick($row, ['Field', 'Type', 'Collation', 'Null', 'Key', 'Default', 'Extra']), $this->db->query('SHOW FULL COLUMNS FROM ' . $table)->fetchAll(\PDO::FETCH_ASSOC)),
            array_map(static fn(array $row): array => $pick($row, ['Table', 'Non_unique', 'Key_name', 'Seq_in_index', 'Column_name', 'Collation', 'Cardinality', 'Sub_part', 'Packed', 'Null', 'Index_type', 'Comment']), $this->db->query('SHOW INDEXES FROM ' . $table)->fetchAll(\PDO::FETCH_ASSOC)),
        );
    }
}
