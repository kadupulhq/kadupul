<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

// Runs the real lib/database.php against the connection globals as test
// fixtures and plugins assign them, on SQLite handles, and prints what the
// helpers did as JSON. Each scenario is its own process so the globals start
// from nothing.

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__, 2);
$scenario = $argv[1] ?? '';
require $root . '/include/global_constants.php';
require $root . '/lib/database.php';
require $root . '/tests/Helpers/PhpSource.php';
// The repository's own helpers, extracted as the other native fixtures do.
foreach (['cacti_sizeof', 'cacti_count', 'clean_up_lines'] as $function) {
    eval(test_php_function_source(file_get_contents($root . '/lib/functions.php'), $function));
}

$logged = [];
$warnings = [];

// Same signature as lib/functions.php; records instead of writing the log.
function cacti_log($string, $output = false, $environ = 'CMDPHP', $level = '')
{
    $GLOBALS['logged'][] = [$string, $environ];

    return true;
}

function cacti_debug_backtrace($entry = '', $html = false, $record = true, $limit = 0, $skip = 0)
{
    $GLOBALS['logged'][] = ['backtrace', $entry];
}

// The message without the file and line, which move with every edit.
set_error_handler(static function (int $errno, string $errstr): bool {
    $GLOBALS['warnings'][] = [$errno, $errstr];

    return true;
});

function state_sqlite(): PDO
{
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_SILENT);

    return $pdo;
}

/** @return array<string, string> which connection globals exist, and what they hold */
function state_globals(): array
{
    $seen = [];
    foreach (['database_sessions', 'database_details', 'database_hostname', 'database_port', 'database_default', 'database_username', 'database_password', 'database_persist', 'local_db_cnn_id', 'remote_db_cnn_id'] as $name) {
        if (array_key_exists($name, $GLOBALS)) {
            $value = $GLOBALS[$name];
            $seen[$name] = is_array($value) ? 'array(' . implode(',', array_keys($value)) . ')' : get_debug_type($value);
        }
    }

    return $seen;
}

$out = [];
switch ($scenario) {
    case 'unconfigured':
        // Nothing assigned the globals: the helpers find no connection, and
        // looking for one leaves the names they declared behind as null.
        $config = ['poller_id' => 1];
        $out['insert_id'] = var_export(db_fetch_insert_id(), true);
        $out['affected'] = var_export(db_affected_rows(), true);
        $out['qstr'] = db_qstr("it's");
        $out['begin'] = var_export(db_begin_transaction(), true);
        $out['execute'] = var_export(db_execute('SELECT 1'), true);
        $out['error'] = db_error();
        $out['cell'] = var_export(db_fetch_cell('SELECT 1'), true);
        $out['close'] = var_export(db_close(), true);
        $out['initialized'] = var_export(db_cacti_initialized(false), true);
        $out['default_database'] = var_export(db_get_default_database(), true);
        $out['switch_remote'] = var_export(db_switch_remote_to_main(), true);
        $out['warnings'] = $warnings;
        $out['logged'] = $logged;
        $out['globals'] = state_globals();

        break;
    case 'assigned':
        // The way fixtures, bin/legacy-*.php handoffs and plugins set the
        // default connection: plain assignment after lib/database.php loads.
        $config = ['poller_id' => 1];
        $database_hostname = 'probe';
        $database_port = 0;
        $database_default = 'state';
        $pdo = state_sqlite();
        $database_sessions = ['probe:0:state' => $pdo];
        $out['create'] = var_export(db_execute('CREATE TABLE items (id INTEGER PRIMARY KEY, name TEXT)'), true);
        $out['insert'] = var_export(db_execute_prepared('INSERT INTO items (name) VALUES (?), (?)', ['one', 'two']), true);
        $out['affected'] = var_export(db_affected_rows(), true);
        $out['insert_id'] = var_export(db_fetch_insert_id(), true);
        $out['cell'] = var_export(db_fetch_cell_prepared('SELECT name FROM items WHERE id = ?', [2]), true);
        $out['row'] = var_export(db_fetch_row('SELECT id, name FROM items WHERE id = 1'), true);
        $out['assoc'] = var_export(db_fetch_assoc('SELECT name FROM items ORDER BY id'), true);
        $out['qstr'] = db_qstr("it's");
        $out['qstr_null'] = db_qstr(null);
        $out['begin'] = var_export(db_begin_transaction(), true);
        $out['in_transaction'] = $pdo->inTransaction();
        $out['rollback'] = var_export(db_rollback_transaction(), true);
        $out['commit_without_transaction'] = var_export(db_commit_transaction(), true);
        $out['total_queries'] = $database_total_queries;
        // A handoff replaces the session under the same key mid-run.
        $other = state_sqlite();
        $other->exec('CREATE TABLE marker (v TEXT)');
        $other->exec("INSERT INTO marker VALUES ('replaced')");
        $database_sessions['probe:0:state'] = $other;
        $out['after_replace'] = var_export(db_fetch_cell('SELECT v FROM marker'), true);
        // So does a reassignment of the key's parts.
        $database_sessions['probe:0:moved'] = $pdo;
        $database_default = 'moved';
        $out['after_rekey'] = var_export(db_fetch_cell('SELECT name FROM items WHERE id = 1'), true);
        $out['warnings'] = $warnings;
        $out['logged'] = $logged;
        $out['globals'] = state_globals();

        break;
    case 'close':
        $config = ['poller_id' => 1];
        $database_hostname = 'probe';
        $database_port = '0';
        $database_default = 'state';
        $database_sessions = ['probe:0:state' => state_sqlite()];
        $error_logged = ['probe:0:state' => true];
        $out['close'] = var_export(db_close(), true);
        $out['key_kept'] = array_key_exists('probe:0:state', $database_sessions);
        $out['session'] = get_debug_type($database_sessions['probe:0:state']);
        $out['error_logged'] = $error_logged;
        $out['close_again'] = var_export(db_close(), true);
        $out['after_close'] = var_export(db_fetch_cell('SELECT 1'), true);
        $out['error'] = db_error();
        $explicit = state_sqlite();
        $out['close_explicit'] = var_export(db_close($explicit), true);
        $out['explicit_after'] = get_debug_type($explicit);
        $out['warnings'] = $warnings;
        $out['globals'] = state_globals();

        break;
    case 'switch':
        $database_hostname = 'collector';
        $database_port = '3306';
        $database_default = 'cacti';
        $local_db_cnn_id = state_sqlite();
        $local_db_cnn_id->exec("CREATE TABLE side (v TEXT); INSERT INTO side VALUES ('local')");
        $remote_db_cnn_id = state_sqlite();
        $remote_db_cnn_id->exec("CREATE TABLE side (v TEXT); INSERT INTO side VALUES ('main')");
        $database_sessions = ['collector:3306:cacti' => $local_db_cnn_id];
        $config = ['poller_id' => 1];
        $out['main_poller_remote'] = var_export(db_switch_remote_to_main(), true);
        $out['main_poller_force'] = var_export(db_force_remote_cnn(), true);
        $out['main_poller_local'] = var_export(db_switch_main_to_local(), true);
        $out['main_poller_side'] = db_fetch_cell('SELECT v FROM side');
        $config['poller_id'] = 2;
        $out['remote'] = var_export(db_switch_remote_to_main(), true);
        $out['remote_side'] = db_fetch_cell('SELECT v FROM side');
        $out['remote_same'] = $database_sessions['collector:3306:cacti'] === $remote_db_cnn_id;
        $out['local'] = var_export(db_switch_main_to_local(), true);
        $out['local_side'] = db_fetch_cell('SELECT v FROM side');
        $out['force'] = var_export(db_force_remote_cnn(), true);
        $out['force_side'] = db_fetch_cell('SELECT v FROM side');
        // The handles are read when the switch runs, not when the library loads.
        $remote_db_cnn_id = false;
        $out['remote_gone'] = var_export(db_switch_remote_to_main(), true);
        $out['remote_gone_session'] = get_debug_type($database_sessions['collector:3306:cacti']);
        $out['warnings'] = $warnings;
        $out['globals'] = state_globals();

        break;
    case 'cached':
        // db_connect_real() hands back what is registered under the key
        // before it tries the network or the credentials.
        $config = ['poller_id' => 1];
        $pdo = state_sqlite();
        $database_sessions = ['db.invalid:1:cached' => $pdo];
        $database_total_queries = 7;
        $out['same'] = db_connect_real('db.invalid', 'user', 'pass', 'cached', 'mysql', 1, 0) === $pdo;
        $out['total_queries'] = $database_total_queries;
        $out['details'] = isset($database_details);
        $out['warnings'] = $warnings;
        $out['globals'] = state_globals();

        break;
    case 'refused':
        // Nothing listens on port 1. The password must not reach the
        // recorded errors.
        $config = ['poller_id' => 1];
        $started = microtime(true);
        $result = db_connect_real('127.0.0.1', 'state-user', 'state-secret-password', 'state', 'mysql', '1', 1);
        $out['result'] = var_export($result, true);
        $out['attempts'] = count($config['DATABASE_ERROR'] ?? []);
        $out['codes'] = array_column($config['DATABASE_ERROR'] ?? [], 'Code');
        $out['leaks'] = str_contains(json_encode($config['DATABASE_ERROR'] ?? []), 'state-secret-password');
        $out['under_two_seconds'] = microtime(true) - $started < 2;
        $out['total_queries'] = $database_total_queries;
        $out['warnings'] = $warnings;
        $out['globals'] = state_globals();

        break;
    default:
        fwrite(STDERR, 'unknown scenario');
        exit(1);
}

print json_encode($out, JSON_THROW_ON_ERROR);
