<?php

declare(strict_types=1);
// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
$root = dirname(__DIR__, 2);
$scenario = $argv[1];
$directory = $argv[2];
require $root . '/include/vendor/autoload.php';
require $root . '/tests/Helpers/NativeChildCoverageEvidence.php';
if (($argv[3] ?? '') === 'coverage') {
    $GLOBALS['nativeChildCoverageSnapshot'] = NativeChildCoverageEvidence::snapshot($root, 'tests/Fixtures/user-copy-native.php', $scenario, array('composer.lock','tests/composer.lock','tests/Symfony/UserCopyTransactionTest.php','cacti.sql','lib/auth.php','lib/functions.php','tests/Helpers/PhpSource.php','lib/database.php','lib/html_validate.php','include/global_constants.php','tests/Fixtures/rrd-process-coverage.php','tests/Helpers/NativeChildCoverageEvidence.php','lib/rrd.php','src/Graphing/Infrastructure/Rrd/ProxyCipher.php','lib/dsdebug.php','lib/rrd_maintenance.php','lib/poller.php','lib/boost.php','lib/api_data_source.php','lib/rrdcheck.php','lib/dsstats.php'));
    define('USER_COPY_TEST_COVERAGE', true);
    define('RRD_TEST_COVERAGE_DIRECTORY', $directory);
    require $root . '/tests/Fixtures/rrd-process-coverage.php';
}
class CopyFaultPdo extends PDO
{
    public string $fault = '';
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        if ($this->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite' && preg_match('/^SHOW COLUMNS FROM ([a-z_]+)$/', $query, $match)) {
            $query = "SELECT name AS Field,type AS Type,CASE WHEN [notnull]=1 THEN 'NO' ELSE 'YES' END AS [Null],trim(dflt_value, char(39)) AS [Default],CASE WHEN name='id' THEN 'auto_increment' ELSE '' END AS Extra FROM pragma_table_info('" . $match[1] . "')";
        }
        return parent::prepare($query, $options);
    }
    public function commit(): bool
    {
        if ($this->fault === 'commit-ended') {
            parent::commit();
            return false;
        }
        return $this->fault === 'commit-denied' ? false : parent::commit();
    }
}
class CopyFaultStatement extends PDOStatement
{
    private bool $faulted = false;
    protected function __construct(private CopyFaultPdo $connection) {}
    public function execute(?array $params = null): bool
    {
        if ($this->connection->fault === 'rollback-ended' && str_starts_with($this->queryString, 'UPDATE user_auth SET `id`')) {
            $this->connection->rollBack();
            $this->faulted = true;
            return true;
        }
        if (in_array($this->connection->fault, array('state-write','noexecute-state'), true) && str_starts_with($this->queryString, 'UPDATE user_auth SET `id`')) {
            $this->faulted = true;
            return $this->connection->fault === 'noexecute-state' ? true : parent::execute($params);
        }
        return parent::execute($params);
    }
    public function errorCode(): ?string
    {
        return $this->faulted ? 'HY000' : parent::errorCode();
    }
}

require $root . '/include/global_constants.php';
require $root . '/tests/Helpers/PhpSource.php';
foreach (array('cacti_count', 'cacti_sizeof', 'clean_up_lines') as $function) {
    eval(test_php_function_source(file_get_contents($root . '/lib/functions.php'), $function));
}
require $root . '/lib/database.php';
require $root . '/lib/html_validate.php';
require $root . '/lib/auth.php';
function cacti_log(...$arguments) {}
function cacti_debug_backtrace(...$arguments) {}
function raise_message(...$arguments) {}
function kill_session_var($name)
{
    unset($_SESSION[$name]);
}
function api_plugin_hook_function($name, $value)
{
    $GLOBALS['copy_hook_calls']++;
    if (($GLOBALS['copy_hook_fault'] ?? false) === true) {
        throw new LogicException('Controlled post-finish hook failure');
    }return $value;
}
$config = array();
$database_log = false;
$database_total_queries = 0;
$database_hostname = 'native-copy';
$database_port = 0;
$database_default = 'copy';
$schemaSql = file_get_contents($root . '/cacti.sql');

$container = 'native';
$case = $scenario;
{
    {
        $ownedTables = array();

        try {
            $db = new CopyFaultPdo(getenv('KADUPUL_USER_COPY_TEST_DSN') ?: 'sqlite::memory:', getenv('KADUPUL_USER_COPY_TEST_USER') ?: null, getenv('KADUPUL_USER_COPY_TEST_PASSWORD') ?: null, array(PDO::ATTR_ERRMODE => PDO::ERRMODE_SILENT));
            $db->setAttribute(PDO::ATTR_STATEMENT_CLASS, array(CopyFaultStatement::class,array($db)));
            $sqlite = $db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
            $database_sessions = array('native-copy:0:copy' => $db);
            foreach (array('user_auth', 'user_auth_perms', 'user_auth_realm', 'settings_user', 'settings_tree', 'user_auth_group', 'user_auth_group_members') as $table) {
                if (!preg_match('/CREATE TABLE `?' . preg_quote($table, '/') . '`? \([\s\S]*?;/', $schemaSql, $matches)) {
                    throw new RuntimeException('Actual table definition missing');
                }
                $ddl = $matches[0];
                if ($sqlite) {
                    $ddl = preg_replace('/\) ENGINE=.*;$/s', ');', $ddl);
                    $ddl = preg_replace('/^\s*(?:UNIQUE )?KEY[^\n]*\n/m', '', $ddl);
                    $ddl = preg_replace('/^\s*PRIMARY KEY \(`id`\),?\n/m', '', $ddl);
                    $ddl = preg_replace('/`id`[^\n]*auto_increment[^\n]*,/i', '`id` INTEGER PRIMARY KEY AUTOINCREMENT,', $ddl);
                    $ddl = preg_replace('/\bunsigned\b/i', '', $ddl);
                    $ddl = preg_replace('/,\s*\)/', ')', $ddl);
                }
                if ($db->exec($ddl) === false) {
                    throw new RuntimeException('Actual table creation failed');
                }
                $ownedTables[] = $table;
            }
            $db->exec("INSERT INTO user_auth(id,username,password,realm,full_name,email_address,enabled,policy_graphs,reset_perms) VALUES (7,'template','source-fixture',0,'Template','template.invalid','on',2,6),(42,'target','target-fixture',0,'Target','target.invalid','on',1,7)");
            $db->exec("UPDATE user_auth SET locked='on',failed_attempts=3,lastfail=993,password_history='destination-history',lastlogin=992,lastchange=991 WHERE id=42");
            $db->exec("INSERT INTO user_auth_group(id,name) VALUES (1,'Old'),(2,'Template')");
            $db->exec('INSERT INTO user_auth_group_members VALUES (1,42),(2,7)');
            $db->exec('INSERT INTO user_auth_perms VALUES (7,9,1),(42,90,3)');
            $db->exec('INSERT INTO user_auth_realm VALUES (18,7),(17,42)');
            $db->exec("INSERT INTO settings_user VALUES (7,'lang','fr-FR'),(42,'lang','en-US'),(42,'auth_credential_generation','destination-binding')");
            $db->exec('INSERT INTO settings_tree VALUES (7,9,1),(42,90,0)');
            $_SESSION = array('sess_user_id' => 42, 'sess_user_realms' => array(17), 'sess_user_perms_key' => 7);
            $copy_hook_calls = 0;
            if (str_starts_with($case, 'delete-') || str_starts_with($case, 'insert-') || $case === 'update-user_auth') {
                [$operation, $table] = explode('-', $case, 2);
                $db->exec("CREATE TRIGGER deny_component BEFORE " . strtoupper($operation) . " ON " . $table . " FOR EACH ROW " . ($sqlite ? "BEGIN SELECT RAISE(ABORT,'Controlled copy refusal'); END" : "SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Controlled copy refusal'"));
            }
            if (in_array($case, array('membership-denied', 'new-denied', 'caller-denied'), true)) {
                $db->exec("CREATE TRIGGER deny_membership BEFORE INSERT ON user_auth_group_members FOR EACH ROW " . ($sqlite ? "BEGIN SELECT RAISE(ABORT,'Controlled membership refusal'); END" : "SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Controlled membership refusal'"));
            }
            if ($case === 'epoch-denied') {
                $db->exec($sqlite ? "CREATE TRIGGER deny_epoch BEFORE UPDATE ON user_auth WHEN NEW.reset_perms != OLD.reset_perms BEGIN SELECT RAISE(ABORT,'Controlled epoch refusal'); END" : "CREATE TRIGGER deny_epoch BEFORE UPDATE ON user_auth FOR EACH ROW BEGIN IF NEW.reset_perms != OLD.reset_perms THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Controlled epoch refusal'; END IF; END");
            }
            if ($case === 'epoch-maximum') {
                $db->exec('UPDATE user_auth SET reset_perms=4294967295 WHERE id=42');
            }
            $caller = str_starts_with($case, 'caller-');
            if ($caller) {
                $db->beginTransaction();
                $db->exec("INSERT INTO user_auth(id,username,password,realm,enabled) VALUES(77,'caller','caller-fixture',0,'on')");
                $db->exec("INSERT INTO settings_user VALUES(77,'caller_work','retained')");
            }
            $snapshot = static function () use ($db): array {
                $state = array();
                foreach (array('user_auth','user_auth_perms','user_auth_realm','settings_user','settings_tree','user_auth_group_members') as $table) {
                    $rows = $db->query('SELECT * FROM ' . $table)->fetchAll(PDO::FETCH_ASSOC);
                    usort($rows, static fn($a, $b) => strcmp(json_encode($a), json_encode($b)));
                    $state[$table] = $rows;
                }
                return $state;
            };
            $before = $snapshot();
            $db->fault = $case;
            $copy_hook_fault = in_array($case, array('hook-throw', 'caller-hook-throw'), true);
            $error = null;
            try {
                $override = $case === 'override-epoch' ? array('reset_perms' => 6) : ($case === 'override-state' ? array('locked' => '','failed_attempts' => 0,'lastfail' => 0,'password_history' => 'template','lastlogin' => 0,'lastchange' => 0) : array());
                $result = user_copy($case === 'self-overwrite' ? 'target' : 'template', str_starts_with($case, 'new-') ? 'new-target' : 'target', 0, 0, true, $override);
            } catch (Throwable $failure) {
                $error = get_class($failure);
                $result = null;
            }
            $denied = str_starts_with($case, 'delete-') || str_starts_with($case, 'insert-') || in_array($case, array('new-denied','caller-denied','update-user_auth','membership-denied','epoch-denied','commit-denied','state-write','noexecute-state'), true);
            $after = $snapshot();
            if (in_array($case, array('commit-ended','rollback-ended'), true)) {
                if ($error !== RuntimeException::class || $copy_hook_calls !== 0 || $db->inTransaction() || ($case === 'rollback-ended' ? $after !== $before : (int) $db->query('SELECT reset_perms FROM user_auth WHERE id=42')->fetchColumn() !== 8)) {
                    throw new RuntimeException('Ended transaction was falsely reported as rolled back');
                }
                $GLOBALS['nativeChildCoverageMarkers'] = array('complete-user-copy-observed');
                echo json_encode(array('engine' => $container,'case' => $case,'result' => null,'error' => $error,'complete_rollback' => false,'caller_owned' => false,'source_sha256' => hash_file('sha256', $root . '/lib/auth.php')), JSON_THROW_ON_ERROR) . "\n";
                return;
            }
            if (in_array($case, array('hook-throw', 'caller-hook-throw'), true)) {
                if ($error !== LogicException::class || $copy_hook_calls !== 1 || $db->inTransaction() !== $caller || (int) $db->query('SELECT reset_perms FROM user_auth WHERE id=42')->fetchColumn() !== 8) {
                    throw new RuntimeException('Post-commit hook contract failed');
                }
                $result = 42;
                $error = null;
            }
            if ($error !== null || $denied && ($result !== false || $after !== $before || $copy_hook_calls !== 0)) {
                throw new RuntimeException('Refused copy failed its complete rollback contract: ' . $case);
            }
            if (!$denied && (!is_numeric($result) || (int) $result <= 0 || $copy_hook_calls !== 1)) {
                throw new RuntimeException('Healthy copy did not complete: ' . $case);
            }
            if ($db->inTransaction() !== $caller) {
                throw new RuntimeException('Caller transaction ownership changed: ' . $case);
            }
            if (!$denied) {
                $id = (int) $result;
                $epoch = (int) $db->query('SELECT reset_perms FROM user_auth WHERE id=' . $id)->fetchColumn();
                $expectedEpoch = $case === 'epoch-maximum' || str_starts_with($case, 'new-') ? 1 : 8;
                if ($epoch !== $expectedEpoch) {
                    throw new RuntimeException('Destination epoch was not advanced: ' . $case);
                }
                if (!str_starts_with($case, 'new-')) {
                    $identity = $db->query('SELECT locked,failed_attempts,lastfail,password_history,lastlogin,lastchange,password FROM user_auth WHERE id=' . $id)->fetch(PDO::FETCH_ASSOC);
                    foreach (array('locked' => 'on','failed_attempts' => 3,'lastfail' => 993,'password_history' => 'destination-history','lastlogin' => 992,'lastchange' => 991,'password' => 'target-fixture') as $field => $expected) {
                        if ((string) $identity[$field] !== (string) $expected) {
                            throw new RuntimeException('Destination authentication state was replaced: ' . $field);
                        }
                    }
                }
                foreach (array('user_auth_perms','user_auth_realm','settings_tree','user_auth_group_members') as $table) {
                    if ((int) $db->query('SELECT COUNT(*) FROM ' . $table . ' WHERE user_id=' . $id)->fetchColumn() !== 1) {
                        throw new RuntimeException('Complete component missing: ' . $case . '/' . $table);
                    }
                }
            }
            if ($caller) {
                if ($db->query("SELECT value FROM settings_user WHERE user_id=77 AND name='caller_work'")->fetchColumn() !== 'retained') {
                    throw new RuntimeException('Caller work was lost');
                }
                $db->rollBack();
                if ($db->query("SELECT value FROM settings_user WHERE user_id=77 AND name='caller_work'")->fetchColumn() !== false) {
                    throw new RuntimeException('Caller could not roll back');
                }
                $expectedRollback = $before;
                $expectedRollback['user_auth'] = array_values(array_filter($expectedRollback['user_auth'], static fn($row) => (int) $row['id'] !== 77));
                $expectedRollback['settings_user'] = array_values(array_filter($expectedRollback['settings_user'], static fn($row) => (int) $row['user_id'] !== 77));
                if ($snapshot() !== $expectedRollback) {
                    throw new RuntimeException('Caller rollback did not restore the complete original policy');
                }
            }
            $GLOBALS['nativeChildCoverageMarkers'] = array('complete-user-copy-observed');
            echo json_encode(array('engine' => $container,'case' => $case,'result' => $result,'complete_rollback' => $denied,'caller_owned' => $caller,'source_sha256' => hash_file('sha256', $root . '/lib/auth.php')), JSON_THROW_ON_ERROR) . "\n";
        } finally {
            if (isset($db) && $db->inTransaction()) {
                $db->rollBack();
            }
            foreach (array_reverse($ownedTables) as $table) {
                $db->exec('DROP TABLE IF EXISTS ' . $table);
            }
        }
    }
}
