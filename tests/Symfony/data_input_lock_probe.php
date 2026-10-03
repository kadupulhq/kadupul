<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

// Run only against the disposable database used by the database-contract matrix.
// Execute the native schema and upgrade, then observe locks on two connections.
$host = getenv('BOOST_DB_HOST') ?: '127.0.0.1';
$port = getenv('BOOST_DB_PORT') ?: '3306';
$database = getenv('BOOST_DB_NAME');
if (!$database) {
    throw new RuntimeException('BOOST_DB_NAME must identify a disposable database.');
}
$dsn = "mysql:host=$host;port=$port;dbname=$database;charset=utf8mb4";
$connect = static fn() => new PDO($dsn, getenv('BOOST_DB_USER'), getenv('BOOST_DB_PASSWORD'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$owner = $connect();
$writer = $connect();
// Schema installation needs a dedicated DDL account; lock owners remain ordinary users.
$installer_connection = getenv('KADUPUL_TEST_MYSQL_ADMIN_USER')
    ? new PDO($dsn, getenv('KADUPUL_TEST_MYSQL_ADMIN_USER'), getenv('KADUPUL_TEST_MYSQL_ADMIN_PASSWORD') ?: '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION])
    : $connect();
$writer->exec('SET SESSION innodb_lock_wait_timeout=1');
$root = dirname(__DIR__, 2);
define('CACTI_VERSION', trim(file_get_contents($root . '/include/cacti_version')));
$source = file_get_contents($root . '/cacti.sql');
$created = [];
function db_install_execute($sql)
{
    return $GLOBALS['installer_connection']->exec($sql);
}
function db_index_exists($table, $name)
{
    $statement = $GLOBALS['owner']->prepare("SHOW INDEX FROM `$table` WHERE Key_name=?");
    $statement->execute([$name]);
    return $statement->fetch() !== false;
}
function __($text, ...$arguments)
{
    return $arguments ? vsprintf($text, $arguments) : $text;
}
function __x($context, $text, ...$arguments)
{
    return __($text, ...$arguments);
}
function cacti_version_compare($old, $new, $operator)
{
    return version_compare($old, $new, $operator);
}
function get_rrdtool_version()
{
    return '1.8';
}
function get_auth_realms()
{
    return [];
}
function read_config_option(...$arguments)
{
    return '';
}
function log_install_always(...$arguments) {}
function api_plugin_hook(...$arguments) {}
function set_install_config_option($name, $value)
{
    if ($name === 'install_cache_db') {
        $GLOBALS['cache_file'] = $value;
    }
}
function get_cacti_cli_version()
{
    return $GLOBALS['owner']->query('SELECT cacti FROM version')->fetchColumn();
}
function cacti_sizeof($value)
{
    return is_countable($value) ? count($value) : 0;
}
function db_execute($sql)
{
    $GLOBALS['installer_connection']->exec($sql);
    return true;
}
function db_fetch_assoc_prepared($sql, $parameters = [], ...$arguments)
{
    $statement = $GLOBALS['owner']->prepare($sql);
    $statement->execute($parameters);
    return $statement->fetchAll(PDO::FETCH_ASSOC);
}
function db_fetch_cell_prepared($sql, $parameters = [], ...$arguments)
{
    $statement = $GLOBALS['owner']->prepare($sql);
    $statement->execute($parameters);
    return $statement->fetchColumn();
}
function populate_reference_fixture(PDO $database): void
{
    for ($id = 1; $id <= 1000; ++$id) {
        $database->exec("INSERT INTO data_input_fields (id,data_input_id) VALUES ($id," . intdiv($id, 10) . ')');
    }
    for ($first = 1; $first <= 10000; $first += 1000) {
        $rows = [];
        for ($id = $first; $id < $first + 1000; ++$id) {
            $rows[] = "($id,$id," . (intdiv($id - 1, 10) + 1) . ",'fixture')";
        }
        $database->exec('INSERT INTO data_template_rrd (id,local_data_id,data_input_field_id,data_source_name) VALUES ' . implode(',', $rows));
    }
}
// Bind the public final-version writer to the actual connection used by migrations.
$database_hostname = $host;
$database_port = $port;
$database_default = $database;
$database_sessions = ["$host:$port:$database" => $installer_connection];
$config = ['base_path' => $root, 'poller_id' => 1, 'connection' => 'local', 'is_web' => false, 'url_path' => '/', 'cacti_server_os' => 'unix'];
require $root . '/include/global_constants.php';
require $root . '/include/global_arrays.php';
require $root . '/lib/installer.php';
$tables = ['data_template_rrd', 'data_input_fields', 'settings_user', 'version', 'poller_output', 'data_source_profiles', 'data_template_data', 'data_source_profiles_rra', 'data_source_profiles_cf'];
foreach ($tables as $table) {
    $check = $owner->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
    $check->execute([$table]);
    if ((int) $check->fetchColumn() !== 0) {
        throw new RuntimeException('Fixture refuses to replace existing table: ' . $table);
    }
}
try {
    $baseline = file_get_contents($root . '/docs/audit_schema.sql');
    foreach (['table_columns', 'table_indexes'] as $table) {
        preg_match('/CREATE TABLE `' . $table . '` \(.*?;\s/s', $baseline, $definition);
        $owner->exec(str_replace('CREATE TABLE', 'CREATE TEMPORARY TABLE', $definition[0]));
        preg_match_all('/INSERT INTO `' . $table . '` VALUES \(\'data_template_rrd\'.*?;/', $baseline, $rows);
        foreach ($rows[0] as $row) {
            $owner->exec($row);
        }
    }
    foreach ($tables as $table) {
        if (in_array($table, ['data_source_profiles', 'data_template_data', 'data_source_profiles_rra', 'data_source_profiles_cf'], true)) {
            // Real transactional prerequisites for the registered profile migration.
            $columns = $table === 'data_source_profiles' ? 'id INT PRIMARY KEY' : 'id INT PRIMARY KEY, data_source_profile_id INT NOT NULL DEFAULT 0';
            $owner->exec("CREATE TABLE `$table` ($columns) ENGINE=InnoDB");
            $created[] = $table;
            continue;
        }
        if ($table === 'poller_output') {
            // The unrelated final installer preflight inspects only its engine.
            // Avoid legacy zero-date defaults in this index-specific fixture.
            $owner->exec('CREATE TABLE poller_output (id INT PRIMARY KEY) ENGINE=InnoDB');
            $created[] = $table;
            continue;
        }
        if (!preg_match('/CREATE TABLE `?' . $table . '`? \(.*?;\s/s', $source, $match)) {
            throw new RuntimeException('Native fixture schema unavailable: ' . $table);
        }
        $owner->exec($match[0]);
        $created[] = $table;
    }
    $owner->exec("INSERT INTO version VALUES ('1.2.31')");
    populate_reference_fixture($owner);
    $modes = ($argv[1] ?? '') === '--from-lts-only' ? ['upgrade-lts-1.2.32'] : ['fresh', 'upgrade-1.2.31', 'upgrade-lts-1.2.32', 'upgrade-1.2.33'];
    foreach ($modes as $mode) {
        if ($mode !== 'fresh') {
            $installedVersion = match ($mode) {
                'upgrade-1.2.31' => '1.2.31', 'upgrade-1.2.33' => '1.2.33', default => '1.2.32'
            };
            require_once $root . '/lib/data_source_profile_integrity.php';
            foreach (data_source_profile_reference_triggers() as $name => $definition) {
                $owner->exec("DROP TRIGGER IF EXISTS `$name`");
            }
            if (db_index_exists('data_template_data', 'data_source_profile_id')) {
                $owner->exec('ALTER TABLE data_template_data DROP INDEX data_source_profile_id');
            }
            if ($installedVersion === '1.2.32') {
                // Snapshot of the actual LTS definitions and provenance, rather
                // than assuming its already-recorded version implies our index.
                $ltsSchema = file_get_contents($root . '/tests/Fixtures/lts-1.2.32-input-schema.sql');
                foreach (['data_template_rrd', 'data_input_fields', 'settings_user'] as $table) {
                    $owner->exec("DROP TABLE `$table`");
                    if (!preg_match('/CREATE TABLE `?' . $table . '`? \(.*?;\s/s', $ltsSchema, $definition)) {
                        throw new RuntimeException('LTS fixture schema unavailable: ' . $table);
                    }
                    $owner->exec($definition[0]);
                }
                populate_reference_fixture($owner);
            } else {
                $owner->exec('ALTER TABLE data_template_rrd DROP INDEX data_input_field_id');
                $owner->exec('DELETE FROM settings_user WHERE user_id > 65535');
                $owner->exec("ALTER TABLE settings_user MODIFY user_id smallint(8) unsigned NOT NULL default '0'");
            }
            $owner->exec("UPDATE version SET cacti = '$installedVersion'");
            // Execute the real installer's version gate using the real registry,
            // starting at an already installed 1.2.31, rather than calling the
            // migration directly and concealing a skipped version.
            $reflection = new ReflectionClass(Installer::class);
            $installer = $reflection->newInstanceWithoutConstructor();
            $reflection->getProperty('old_cacti_version')->setValue($installer, $installedVersion);
            ob_start();
            try {
                $result = $reflection->getMethod('upgradeDatabase')->invoke($installer);
            } finally {
                ob_end_clean();
            }
            // Migration readiness does not publish the current release: install()
            // confirms that marker only after all required contracts succeed.
            $migrationVersion = get_cacti_cli_version();
            if ($result !== false || version_compare($migrationVersion, $installedVersion, '<')
                || !version_compare($migrationVersion, CACTI_VERSION, '<')) {
                throw new RuntimeException('Native version-gated migration published an invalid intermediate marker for ' . $installedVersion . '.');
            }
            upgrade_to_1_2_33(); // An already upgraded installation must remain valid.
            if (!data_source_profile_reference_guards_available() || !db_index_exists('data_template_data', 'data_source_profile_id')) {
                throw new RuntimeException('Registered profile migration was skipped for installed ' . $installedVersion);
            }
            upgrade_to_1_2_34(); // Profile migration remains idempotent.
            // A real server refusal must retain the intermediate marker on a
            // separate connection; removing it must allow the public writer retry.
            $installer_connection->exec("CREATE TRIGGER standalone_version_refusal BEFORE UPDATE ON version FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='standalone final marker refusal'");
            try {
                $installer_connection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_SILENT);
                $refused = $installer_connection->prepare('UPDATE version SET cacti = ?');
                if ($refused->execute([CACTI_VERSION]) || $refused->errorCode() !== '45000'
                    || $writer->query('SELECT cacti FROM version')->fetchColumn() !== $migrationVersion) {
                    throw new RuntimeException('The native final-version refusal fixture did not reject an actual write.');
                }
                $installer_connection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                if (Installer::recordInstalledVersion()
                    || $writer->query('SELECT cacti FROM version')->fetchColumn() !== $migrationVersion
                    || $installer_connection->inTransaction()) {
                    throw new RuntimeException('Failed final confirmation did not retain the migration marker.');
                }
            } finally {
                $installer_connection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                $installer_connection->exec('DROP TRIGGER standalone_version_refusal');
            }
            if (!Installer::recordInstalledVersion()
                || $writer->query('SELECT cacti FROM version')->fetchColumn() !== CACTI_VERSION
                || $installer_connection->inTransaction()) {
                throw new RuntimeException('Successful final confirmation did not publish the current release.');
            }
            echo 'PASS: ' . $mode . " migration retains an intermediate marker; failed final write and successful retry are observed on an independent connection.\n";
        }
        foreach ([65536, 16777215] as $userId) {
            $statement = $owner->prepare('REPLACE INTO settings_user (user_id, name, value) VALUES (?, ?, ?)');
            $statement->execute([$userId, 'auth_credential_generation', 'fixture-generation']);
            $statement = $owner->prepare('SELECT value FROM settings_user WHERE user_id=? AND name=?');
            $statement->execute([$userId, 'auth_credential_generation']);
            if ($statement->fetchColumn() !== 'fixture-generation') {
                throw new RuntimeException('Credential metadata does not support the full unsigned mediumint user ID range.');
            }
        }
        $owner->query('ANALYZE TABLE data_template_rrd,data_input_fields')->fetchAll();
        $actual = $owner->query("SHOW INDEX FROM data_template_rrd WHERE Key_name='data_input_field_id'")->fetch(PDO::FETCH_ASSOC);
        $expected = $owner->query("SELECT * FROM table_indexes WHERE idx_key_name='data_input_field_id'")->fetch(PDO::FETCH_ASSOC);
        foreach (['Non_unique' => 'idx_non_unique', 'Seq_in_index' => 'idx_seq_in_index', 'Column_name' => 'idx_column_name', 'Index_type' => 'idx_index_type'] as $live => $audit) {
            if (!$expected || !$actual || (string) $actual[$live] !== (string) $expected[$audit]) {
                throw new RuntimeException('RRD field index differs from the native audit baseline.');
            }
        }
        $column = $owner->query("SHOW COLUMNS FROM data_template_rrd WHERE Field='data_input_field_id'")->fetch(PDO::FETCH_ASSOC);
        if ($column['Key'] !== $owner->query("SELECT table_key FROM table_columns WHERE table_field='data_input_field_id'")->fetchColumn()) {
            throw new RuntimeException('RRD field column differs from the native audit baseline.');
        }
        foreach (['SELECT id FROM data_template_rrd WHERE data_input_field_id=100 FOR UPDATE', 'SELECT r.id FROM data_template_rrd r INNER JOIN data_input_fields f ON f.id=r.data_input_field_id WHERE f.data_input_id=10 FOR UPDATE'] as $query) {
            $plan = $owner->query('EXPLAIN FORMAT=TRADITIONAL ' . $query)->fetchAll(PDO::FETCH_ASSOC);
            $rrd = array_values(array_filter($plan, static fn(array $row): bool => in_array($row['table'], ['data_template_rrd', 'r'], true)))[0] ?? null;
            if (($rrd['key'] ?? null) !== 'data_input_field_id') {
                throw new RuntimeException($mode . ': RRD reference lookup does not use the field index.');
            }
            $owner->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            $owner->beginTransaction();
            $owner->query($query)->fetchAll();
            $writer->beginTransaction();
            $writer->exec("UPDATE data_template_rrd SET data_source_name='unrelated' WHERE id=9000");
            $writer->rollBack();
            $writer->beginTransaction();
            $blocked = false;
            try {
                $writer->exec("UPDATE data_template_rrd SET data_source_name='related' WHERE id=991");
            } catch (PDOException $error) {
                if (($error->errorInfo[1] ?? null) !== 1205) {
                    throw $error;
                }
                $blocked = true;
            } finally {
                $writer->rollBack();
            }
            if (!$blocked) {
                throw new RuntimeException($mode . ': selected RRD reference was not locked.');
            }
            $owner->rollBack();
        }
        echo 'PASS: ' . $mode . " preserves full-range user IDs and indexed reference locks permit unrelated writes.\n";
    }
} finally {
    foreach ([$owner, $writer, $installer_connection] as $db) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
    }
    foreach (array_reverse($created) as $table) {
        $owner->exec("DROP TABLE `$table`");
    }
    if (isset($cache_file) && is_file($cache_file)) {
        unlink($cache_file);
    }
}
