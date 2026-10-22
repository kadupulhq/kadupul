<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class PackageScriptUpgradeTest extends TestCase
{
    private PDO $db;

    protected function setUp(): void
    {
        $dsn = getenv('KADUPUL_TEST_MYSQL_DSN');
        if ($dsn === false || $dsn === '') {
            self::markTestSkipped('Set KADUPUL_TEST_MYSQL_DSN to a task-owned native database.');
        }
        $this->db = new PDO($dsn, getenv('KADUPUL_TEST_MYSQL_USER') ?: 'root', getenv('KADUPUL_TEST_MYSQL_PASSWORD') ?: '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_SILENT]);
        $root = dirname(__DIR__, 2);
        require $root . '/include/global_constants.php';
        require __DIR__ . '/Fixtures/forward_schema_repair_bridge.php';
        require $root . '/install/upgrades/1_2_36.php';
        $GLOBALS['forward_schema_pdo'] = $this->db;
        $GLOBALS['forward_schema_status'] = [];

        // The columns and collation cacti.sql gives these tables.
        $this->execute("CREATE TABLE data_input (id mediumint unsigned NOT NULL AUTO_INCREMENT, hash varchar(32) NOT NULL DEFAULT '', name varchar(200) NOT NULL DEFAULT '', input_string varchar(512) DEFAULT NULL, type_id tinyint unsigned NOT NULL DEFAULT 0, PRIMARY KEY (id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $this->execute("CREATE TABLE settings (name varchar(75) NOT NULL DEFAULT '', value varchar(4096) NOT NULL DEFAULT '', PRIMARY KEY (name)) ENGINE=InnoDB");
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            $this->db->exec('DROP TABLE IF EXISTS data_input, settings');
        }
    }

    private function execute(string $sql, array $params = []): void
    {
        $statement = $this->db->prepare($sql);
        self::assertNotFalse($statement);
        self::assertTrue($statement->execute($params), 'Actual native fixture SQL failed.');
    }

    /** @return array<string, string> */
    private function inputStrings(): array
    {
        return $this->db->query('SELECT hash, input_string FROM data_input ORDER BY id')->fetchAll(PDO::FETCH_KEY_PAIR);
    }

    public function testOnlyShippedInputStringsMoveToThePhpPorts(): void
    {
        $inputs = upgrade_package_script_inputs();
        foreach ($inputs as $hash => [$shipped]) {
            $this->execute('INSERT INTO data_input (hash, name, input_string, type_id) VALUES (?, ?, ?, 1)', [$hash, $hash, $shipped]);
        }
        $customized = [
            '95ed0993eb3095f9920d431ac80f4231' => 'perl /opt/site/loadavg_multi.pl',
            // Equal under the case- and trailing-space-insensitive collation, but not what Kadupul shipped.
            '79a284e136bb6b061c6f96ec219ac448' => 'PERL <path_cacti>/scripts/unix_users.pl <username> ',
        ];
        foreach ($customized as $hash => $input) {
            $this->execute('UPDATE data_input SET input_string = ? WHERE hash = ?', [$input, $hash]);
        }
        $this->execute("INSERT INTO data_input (hash, name, input_string, type_id) VALUES ('cc948e4de13f32b6aea45abaadd287a3', 'Unix - Get Web Hits', 'perl <path_cacti>/scripts/webhits.pl <log_path>', 1)");
        $this->execute("INSERT INTO settings VALUES ('poller_replicate_data_input_crc', 'before')");

        upgrade_to_1_2_36();
        $first = $this->inputStrings();
        upgrade_to_1_2_36();

        self::assertNotContains(DB_STATUS_ERROR, $GLOBALS['forward_schema_status']);
        self::assertSame($first, $this->inputStrings(), 'Running the step twice must change nothing more.');
        foreach ($inputs as $hash => [, $port]) {
            self::assertSame($customized[$hash] ?? $port, $first[$hash]);
        }
        self::assertSame('perl <path_cacti>/scripts/webhits.pl <log_path>', $first['cc948e4de13f32b6aea45abaadd287a3']);
        self::assertNotSame('before', $this->db->query("SELECT value FROM settings WHERE name = 'poller_replicate_data_input_crc'")->fetchColumn());
    }

    public function testShippedStringsMatchTheFreshInstallSchema(): void
    {
        $sql = file_get_contents(dirname(__DIR__, 2) . '/cacti.sql');
        self::assertIsString($sql);
        foreach (upgrade_package_script_inputs() as $hash => [, $port]) {
            if (str_contains($sql, "'" . $hash . "'")) {
                self::assertStringContainsString("'" . $hash . "'", $sql);
                self::assertMatchesRegularExpression('/\'' . $hash . '\',\'[^\']*\',\'' . preg_quote($port, '/') . '\'/', $sql, 'A fresh install must ship the same port the upgrade applies.');
            }
        }
    }
}
