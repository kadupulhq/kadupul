<?php
declare(strict_types=1);
// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-2.0-or-later

namespace RemoteSyncNativeReview;

require_once dirname(__DIR__) . '/Helpers/PhpSource.php';
$root = dirname(__DIR__, 2);
$source = file_get_contents($root . '/lib/poller.php');
foreach (['replicate_out_table', 'replicate_out_execute', 'replicate_out_replace_execute', 'replicate_out_replace_finish'] as $name) {
    eval('namespace ' . __NAMESPACE__ . ';' . \test_php_function_source($source, $name));
}
foreach (['POLLER_VERBOSITY_NONE' => 0, 'POLLER_VERBOSITY_LOW' => 2] as $name => $value) {
    if (!defined($name)) define($name, $value);
}
function connection($conn = false) { return $conn ?: $GLOBALS['review_local']; }
function db_fetch_row($sql, $log = true, $conn = false) {
    if ($conn && $GLOBALS['review_mode'] === 'metadata' && str_starts_with($sql, 'SHOW CREATE')) return false;
    return connection($conn)->query($sql)->fetch(\PDO::FETCH_ASSOC);
}
function db_fetch_assoc($sql, $log = true, $conn = false) { return connection($conn)->query($sql)->fetchAll(\PDO::FETCH_ASSOC); }
function db_fetch_cell($sql, $column = '', $log = true, $conn = false) { return connection($conn)->query($sql)->fetchColumn(); }
function db_table_exists($table, $log = true, $conn = false) { try { return connection($conn)->query('SHOW CREATE TABLE ' . $table)->fetchColumn() !== false; } catch (\PDOException $e) { return false; } }
function db_column_exists($table, $column, $log = true, $conn = false) { return in_array($column, array_column(db_fetch_assoc('SHOW COLUMNS FROM ' . $table, $log, $conn), 'Field'), true); }
function db_execute($sql, $log = true, $conn = false) {
    $GLOBALS['review_trace'][] = strtok($sql, ' ');
    if ($GLOBALS['review_mode'] === 'exception' && str_starts_with($sql, 'REPLACE')) throw new \RuntimeException('Injected execution failure');
    if (in_array($GLOBALS['review_mode'], ['schema_insert', 'insert_failure'], true) && str_starts_with($sql, 'REPLACE')) return false;
    if ($GLOBALS['review_mode'] === 'empty_failure' && str_starts_with($sql, 'DELETE')) return false;
    try { $GLOBALS['review_affected'] = connection($conn)->exec($sql); return $GLOBALS['review_affected'] !== false; }
    catch (\PDOException $e) { $GLOBALS['review_errors'][] = $e->getCode(); return false; }
}
function db_affected_rows($conn = false) { return $GLOBALS['review_affected'] ?? 0; }
function db_qstr($value, $conn = false) { return $value === null ? 'NULL' : connection($conn)->quote((string)$value); }
function cacti_sizeof($v) { return is_countable($v) ? count($v) : 0; }
function array_rekey($rows, $key, $value) { return array_column($rows, $value, $key); }
function cacti_log($message, ...$args) { $GLOBALS['review_logs'][] = $message; }
function replicate_log($message, ...$args) { cacti_log($message); }

test('native replacement preserves prior rows on failure', function ($mode) use ($root) {
    $base = 'mysql:host=' . (getenv('BOOST_DB_HOST') ?: '127.0.0.1') . ';port=' . (getenv('BOOST_DB_PORT') ?: '3306') . ';dbname=' . (getenv('BOOST_DB_NAME') ?: 'cacti_boost_contract') . ';charset=utf8mb4';
    $user = getenv('BOOST_DB_USER') ?: 'root'; $password = getenv('BOOST_DB_PASSWORD') ?: '';
    $local = $remote = null;
    try {
        $local = new \PDO($base, $user, $password, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $remote = new \PDO($base, $user, $password, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        preg_match('/CREATE TABLE data_input \([\s\S]*?;/', file_get_contents($root . '/cacti.sql'), $match);
        if (!isset($match[0])) throw new \RuntimeException('Shipped data_input DDL not found');
        foreach ([$local, $remote] as $db) { $db->exec(str_replace('CREATE TABLE', 'CREATE TEMPORARY TABLE', $match[0])); $db->exec("SET SESSION sql_mode='STRICT_ALL_TABLES'"); }
        $remote->exec("INSERT INTO data_input(id,name) VALUES(1,'previous')");
        $local->exec("INSERT INTO data_input(id,name) VALUES(1,'replacement')");
        $GLOBALS['review_local'] = $local;
        $GLOBALS['review_mode'] = $mode;
        $GLOBALS['review_trace'] = $GLOBALS['review_errors'] = $GLOBALS['review_logs'] = [];
        $GLOBALS['replicate_out_success'] = true;
        if (in_array($mode, ['metadata', 'borrowed'], true)) $remote->exec("ALTER TABLE data_input ADD CONSTRAINT reject_new CHECK(name <> 'replacement')");
        if ($mode === 'schema_insert') $remote->exec("ALTER TABLE data_input ADD old_column INT NOT NULL DEFAULT 0");
        if ($mode === 'type') $remote->exec("ALTER TABLE data_input MODIFY name VARCHAR(100) NOT NULL DEFAULT ''");
        if ($mode === 'default') $remote->exec("ALTER TABLE data_input MODIFY input_string VARCHAR(512) DEFAULT ''");
        if ($mode === 'renamed') $remote->exec("ALTER TABLE data_input CHANGE name legacy_name VARCHAR(200) NOT NULL DEFAULT ''");
        if ($mode === 'borrowed') {
            $remote->exec('CREATE TEMPORARY TABLE caller_work(id INT PRIMARY KEY) ENGINE=InnoDB');
            $remote->beginTransaction(); $remote->exec('INSERT INTO caller_work VALUES(7)');
        }
        $data = $local->query('SELECT * FROM data_input')->fetchAll(\PDO::FETCH_ASSOC);
        if (in_array($mode, ['empty', 'empty_failure'], true)) $data = [];
        $ok = replicate_out_table($remote, $data, 'data_input', 2);
        $column = $mode === 'renamed' ? 'legacy_name' : 'name';
        $names = $remote->query('SELECT ' . $column . ' FROM data_input')->fetchAll(\PDO::FETCH_COLUMN);
        $result = ['mode' => $mode, 'returned' => $ok, 'rows' => $names, 'transaction_active' => $remote->inTransaction(), 'statements' => $GLOBALS['review_trace']];
        if ($mode === 'empty') expect($ok)->toBeTrue()->and($names)->toBe([]);
        elseif ($mode === 'success') expect($ok)->toBeTrue()->and($names)->toBe(['replacement']);
        else expect($ok)->toBeFalse()->and($names)->toBe(['previous']);
        if ($mode === 'borrowed') expect($remote->inTransaction())->toBeTrue();
    } finally {
        if ($remote && $remote->inTransaction()) $remote->rollBack();
        $local = $remote = null;
    }
})->with(['success', 'metadata', 'borrowed', 'schema_insert', 'renamed', 'type', 'default', 'insert_failure', 'exception', 'empty', 'empty_failure']);
