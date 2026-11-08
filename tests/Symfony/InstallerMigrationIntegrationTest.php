<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Kadupul\Platform\Infrastructure\Legacy\InstallerCollectorConfiguration;
use Kadupul\Platform\Infrastructure\Legacy\InstallerAutomationRepair;
use Kadupul\Platform\Infrastructure\Legacy\InstallerColorImport;
use Kadupul\Platform\Infrastructure\Legacy\LegacyConnectionRegistry;
use Kadupul\Platform\Infrastructure\Legacy\NativeInstallerFunctions;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

/** Native driver/schema acceptance for the extracted installer support services. */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class InstallerMigrationIntegrationTest extends TestCase
{
    /** Disposable database administrator selected only through explicit test configuration. */
    private \PDO $administrator;
    /** The exact selected native local session. */
    private \PDO $local;
    /** Task-owned local database name. */
    private string $schema;
    /** Task-owned configuration/log directory. */
    private string $directory;
    /** Server address from the explicitly supplied disposable DSN. */
    private string $databaseHost;
    /** Server port from the explicitly supplied disposable DSN. */
    private string $databasePort;
    /** Guard cleanup when native fixture setup fails. */
    private bool $created = false;

    /** Load declaration-only native libraries after establishing an explicitly owned database. */
    protected function setUp(): void
    {
        $dsn = getenv('KADUPUL_TEST_MYSQL_DSN');
        if (!is_string($dsn) || !str_starts_with($dsn, 'mysql:')) {
            $this->markTestSkipped('An explicitly configured disposable MySQL/MariaDB server is required.');
        }
        $this->assertSame(1, preg_match('/(?:^mysql:|;)host=([^;]+)/', $dsn, $host));
        $this->databaseHost = $host[1];
        $this->databasePort = preg_match('/;port=([0-9]+)/', $dsn, $port) === 1 ? $port[1] : '3306';
        $this->administrator = new \PDO($dsn, getenv('KADUPUL_TEST_MYSQL_USER') ?: 'root', getenv('KADUPUL_TEST_MYSQL_PASSWORD') ?: '', [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $this->schema = 'installer_services_' . bin2hex(random_bytes(10));
        $this->administrator->exec('CREATE DATABASE ' . $this->schema . ' CHARACTER SET utf8mb4');
        $this->created = true;
        $this->local = $this->connection($this->schema);
        $this->directory = sys_get_temp_dir() . '/installer-native-' . bin2hex(random_bytes(10));
        mkdir($this->directory, 0700);
        mkdir($this->directory . '/log', 0700);
        mkdir($this->directory . '/include', 0700);
        $root = dirname(__DIR__, 2);
        // Production installer bootstrap disables ordinary metadata caches during DDL.
        define('IN_CACTI_INSTALL', true);
        require $root . '/include/global_constants.php';
        require $root . '/lib/functions.php';
        require $root . '/lib/database.php';
        require $root . '/install/functions.php';
        $GLOBALS['config'] = ['base_path' => $root, 'poller_id' => 1, 'is_web' => false, 'cacti_server_os' => 'unix', 'cacti_db_version' => 'new_install'];
        $GLOBALS['database_hostname'] = 'database';
        $GLOBALS['database_port'] = 3306;
        $GLOBALS['database_default'] = $this->schema;
        $GLOBALS['database_username'] = getenv('KADUPUL_TEST_MYSQL_USER') ?: 'root';
        $GLOBALS['database_password'] = getenv('KADUPUL_TEST_MYSQL_PASSWORD') ?: '';
        $GLOBALS['database_total_queries'] = 0;
        $GLOBALS['database_last_error'] = false;
        $GLOBALS['local_db_cnn_id'] = $this->local;
        $GLOBALS['cacti_upgrade_version'] = '1.3.0';
        $GLOBALS['database_upgrade_status'] = [];
        $GLOBALS['settings'] = [];
        $_SESSION = [];
        LegacyConnectionRegistry::setDefaultSession($this->local);
        $source = file_get_contents($root . '/cacti.sql');
        foreach (['settings', 'colors', 'poller'] as $table) {
            $this->assertSame(1, preg_match('~CREATE TABLE [`]?' . $table . '[`]?\s*\(.*?;~s', $source, $match));
            $this->importTable($this->local, $match[0]);
        }
        $this->local->prepare('INSERT INTO settings (name, value) VALUES (?, ?)')->execute(['path_cactilog', $this->directory . '/log/cacti.log']);
    }

    /** Bind each connection to its owned schema without changing the native default implicitly. */
    private function connection(string $schema): \PDO
    {
        $dsn = preg_replace('/;dbname=[^;]*/i', '', getenv('KADUPUL_TEST_MYSQL_DSN')) . ';dbname=' . $schema;
        return new \PDO($dsn, getenv('KADUPUL_TEST_MYSQL_USER') ?: 'root', getenv('KADUPUL_TEST_MYSQL_PASSWORD') ?: '', [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
    }

    /** Import fixture DDL under the dump's own session mode, then restore runtime strictness. */
    private function importTable(\PDO $connection, string $ddl): void
    {
        $mode = $connection->query('SELECT @@SESSION.sql_mode')->fetchColumn();
        $this->assertIsString($mode);
        $source = file_get_contents(dirname(__DIR__, 2) . '/cacti.sql');
        $this->assertSame(1, preg_match('/SET @sqlmode= "";\s*SET SESSION sql_mode = @sqlmode;/', $source, $header));
        try {
            foreach (explode(';', $header[0]) as $statement) {
                if (trim($statement) !== '') {
                    $connection->exec($statement);
                }
            }
            $connection->exec($ddl);
        } finally {
            $connection->prepare('SET SESSION sql_mode = ?')->execute([$mode]);
        }
    }

    /** Preserve failed setup evidence while always removing owned schemas and fixture files. */
    protected function tearDown(): void
    {
        if ($this->created) {
            if (isset($this->local) && $this->local->inTransaction()) {
                $this->local->rollBack();
            }
            $this->administrator->exec('DROP DATABASE ' . $this->schema);
        }
        if (isset($this->directory)) {
            (new Filesystem())->remove($this->directory);
        }
    }

    /** Prepared native helpers execute actual DDL and preserve the caller's settings transaction. */
    public function testNativeMigrationHelpersUseTheInstalledSchemaAndPreserveCallerWork(): void
    {
        ob_start();
        try {
            $this->assertSame(DB_STATUS_SUCCESS, \db_install_execute('CREATE TABLE migration_fixture (id INT NOT NULL PRIMARY KEY) ENGINE=InnoDB'));
            $this->assertSame(DB_STATUS_SUCCESS, \db_install_add_column('migration_fixture', ['name' => 'value', 'type' => 'varchar(64)', 'NULL' => true]));
            $this->assertSame(DB_STATUS_SUCCESS, \db_install_add_key('migration_fixture', 'KEY', 'value_idx', ['value']));
            $this->assertSame(DB_STATUS_SKIPPED, \db_install_add_key('migration_fixture', 'KEY', 'value_idx', ['value']));
            $this->assertSame(DB_STATUS_SUCCESS, \db_install_execute('INSERT INTO migration_fixture VALUES (?, ?)', [1, 'native-value']));
            $this->assertSame(['status' => DB_STATUS_SUCCESS, 'data' => 'native-value'], \db_install_fetch_cell('SELECT value FROM migration_fixture WHERE id = ?', [1]));
            $GLOBALS['database_last_error'] = 'stale prior error';
            $this->assertSame(DB_STATUS_SUCCESS, \db_install_fetch_row('SELECT * FROM migration_fixture')['status']);
            $this->assertSame(DB_STATUS_SUCCESS, \db_install_fetch_assoc('SELECT * FROM migration_fixture')['status']);
            $this->assertSame(DB_STATUS_SUCCESS, \db_install_drop_key('migration_fixture', 'KEY', 'value_idx'));
            $this->assertSame(DB_STATUS_SUCCESS, \db_install_drop_column('migration_fixture', 'value'));
            $this->assertSame(DB_STATUS_SUCCESS, \db_install_rename_table('migration_fixture', 'renamed_fixture'));
            $this->assertSame(DB_STATUS_SUCCESS, \db_install_drop_table('renamed_fixture'));
            $this->local->exec("INSERT INTO settings (name, value) VALUES ('rename_old','kept'), ('rename_new','previous')");
            $this->local->beginTransaction();
            \db_install_swap_setting('rename_old', 'rename_new');
            $this->assertTrue($this->local->inTransaction());
            $this->assertSame('kept', $this->local->query("SELECT value FROM settings WHERE name='rename_new'")->fetchColumn());
            $this->local->rollBack();
            $this->assertSame('kept', $this->local->query("SELECT value FROM settings WHERE name='rename_old'")->fetchColumn());
            $this->assertSame('previous', $this->local->query("SELECT value FROM settings WHERE name='rename_new'")->fetchColumn());
            $this->assertSame($this->local, LegacyConnectionRegistry::select(false));
        } finally {
            ob_end_clean();
        }
    }

    /** Shipped rules may refer to bundled templates that the operator did not select. */
    public function testAutomationRepairPreservesRulesForUnselectedTemplates(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/cacti.sql');
        $this->assertIsString($source);
        foreach (['automation_graph_rules', 'snmp_query', 'snmp_query_graph'] as $table) {
            $this->assertSame(1, preg_match('~CREATE TABLE [`]?' . $table . '[`]?\s*\(.*?;~s', $source, $match));
            $this->importTable($this->local, $match[0]);
        }
        $this->assertSame(1, preg_match('~INSERT INTO `automation_graph_rules` VALUES .*?;~s', $source, $seed));
        $this->local->exec($seed[0]);
        $before = $this->local->query('SELECT * FROM automation_graph_rules ORDER BY id')->fetchAll(\PDO::FETCH_ASSOC);
        $this->assertNotEmpty($before);
        (new InstallerAutomationRepair(new NativeInstallerFunctions()))->repair();
        $this->assertSame($before, $this->local->query('SELECT * FROM automation_graph_rules ORDER BY id')->fetchAll(\PDO::FETCH_ASSOC));
    }

    /** A handled earlier native query error must not poison a new repair or hide its own failures. */
    public function testAutomationRepairResetsPriorErrorsButStillRejectsCurrentReadFailures(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/cacti.sql');
        $this->assertIsString($source);
        foreach (['automation_graph_rules', 'snmp_query', 'snmp_query_graph'] as $table) {
            $this->assertSame(1, preg_match('~CREATE TABLE [`]?' . $table . '[`]?\s*\(.*?;~s', $source, $match));
            $this->importTable($this->local, $match[0]);
        }
        $this->assertSame(1, preg_match('~INSERT INTO `automation_graph_rules` VALUES .*?;~s', $source, $seed));
        $this->local->exec($seed[0]);
        $before = $this->local->query('SELECT * FROM automation_graph_rules ORDER BY id')->fetchAll(\PDO::FETCH_ASSOC);
        $this->assertFalse(\db_fetch_cell_prepared('SELECT unavailable_fixture_column FROM snmp_query'));
        $this->assertNotEmpty(\db_error());
        $repair = new InstallerAutomationRepair(new NativeInstallerFunctions());
        $repair->repair();
        $this->assertFalse(\db_error());
        $this->assertSame($before, $this->local->query('SELECT * FROM automation_graph_rules ORDER BY id')->fetchAll(\PDO::FETCH_ASSOC));
        $this->local->exec('DROP TABLE snmp_query');
        try {
            $repair->repair();
            $this->fail('A new native template read failure was accepted.');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('template references', $error->getMessage());
            $this->assertNotEmpty(\db_error());
            $this->assertSame($before, $this->local->query('SELECT * FROM automation_graph_rules ORDER BY id')->fetchAll(\PDO::FETCH_ASSOC));
        }
    }

    /** Release color imports are idempotent and execute native bound writes on the actual schema. */
    public function testNativeColorImportIsIdempotentWithoutChangingCustomColors(): void
    {
        $this->local->exec("INSERT INTO colors (name, hex, read_only) VALUES ('Custom','A1B2C3','')");
        $import = new InstallerColorImport(dirname(__DIR__, 2), new NativeInstallerFunctions());
        $this->assertTrue($import->import());
        $first = $this->local->query('SELECT name, hex, read_only FROM colors ORDER BY id')->fetchAll(\PDO::FETCH_ASSOC);
        $this->assertGreaterThan(100, count($first));
        $this->assertTrue($import->import());
        $this->assertSame($first, $this->local->query('SELECT name, hex, read_only FROM colors ORDER BY id')->fetchAll(\PDO::FETCH_ASSOC));
        $this->assertSame('Custom', $this->local->query("SELECT name FROM colors WHERE hex='A1B2C3'")->fetchColumn());
    }

    /** Distinct local/remote IDs prove that collector registration reads the supplied remote session. */
    public function testCollectorRegistrationUsesRemoteIdentityRatherThanTheLocalDefault(): void
    {
        $remoteSchema = 'installer_remote_' . bin2hex(random_bytes(10));
        $this->administrator->exec('CREATE DATABASE ' . $remoteSchema . ' CHARACTER SET utf8mb4');
        try {
            $remote = $this->connection($remoteSchema);
            $this->importTable($remote, 'CREATE TABLE poller LIKE ' . $this->schema . '.poller');
            $hostname = gethostname();
            $remote->prepare('INSERT INTO poller (id, hostname, last_update, last_status, last_sync) VALUES (?, ?, NOW(), NOW(), NOW())')->execute([7, $hostname]);
            $this->local->prepare('INSERT INTO poller (id, hostname, last_update, last_status, last_sync) VALUES (?, ?, NOW(), NOW(), NOW())')->execute([9, $hostname]);
            $file = $this->directory . '/include/config.php';
            file_put_contents($file, '<?php $poller_id = 1; // retained comment');
            $service = new InstallerCollectorConfiguration($this->directory, new NativeInstallerFunctions());
            $this->assertSame('', $service->update([$this->databaseHost, getenv('KADUPUL_TEST_MYSQL_USER') ?: 'root', getenv('KADUPUL_TEST_MYSQL_PASSWORD') ?: '', $remoteSchema, 'mysql', $this->databasePort, 1, false, false, false, false], []));
            $this->assertSame('<?php $poller_id = 7; // retained comment', file_get_contents($file));
            $this->assertSame($this->local, LegacyConnectionRegistry::select(false));
            $this->assertSame([['id' => 7]], $remote->query('SELECT id FROM poller')->fetchAll(\PDO::FETCH_ASSOC));
            $this->assertSame([['id' => 9]], $this->local->query('SELECT id FROM poller')->fetchAll(\PDO::FETCH_ASSOC));
            $this->assertFalse(function_exists('__'));
            $remote->exec('DELETE FROM poller');
            try {
                $service->update([$this->databaseHost, getenv('KADUPUL_TEST_MYSQL_USER') ?: 'root', getenv('KADUPUL_TEST_MYSQL_PASSWORD') ?: '', $remoteSchema, 'mysql', $this->databasePort, 1, false, false, false, false], []);
                $this->fail('Incomplete native runtime initialized a collector.');
            } catch (\RuntimeException $error) {
                $this->assertStringContainsString('authenticated native installer runtime', $error->getMessage());
            }
            $this->assertSame(0, (int) $remote->query('SELECT COUNT(*) FROM poller')->fetchColumn());

        } finally {
            $this->administrator->exec('DROP DATABASE ' . $remoteSchema);
        }
    }
    /** An incomplete native bootstrap fails before path discovery or diagnostic writes. */
    public function testPathCatalogRequiresTheNativeTranslationRuntime(): void
    {
        $this->assertFalse(function_exists('__'));
        $service = new \Kadupul\Platform\Infrastructure\Legacy\InstallerToolPaths(new NativeInstallerFunctions());
        $this->expectException(\RuntimeException::class);
        $service->tool('php_binary', ['unix' => '/bin/php'], ['cacti_server_os' => 'unix'], []);
    }

}
