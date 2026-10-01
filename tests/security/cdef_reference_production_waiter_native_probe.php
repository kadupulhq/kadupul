<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

$root = dirname(__DIR__, 2);
require $root . '/lib/cdef_reference.php';
require $root . '/lib/database.php';
require $root . '/lib/functions.php';
require $root . '/lib/plugins.php';
require $root . '/lib/auth.php';
require $root . '/include/global_constants.php';
require $root . '/lib/import.php';
define('IN_CACTI_INSTALL', 1);
define('CACTI_VERSION', trim(file_get_contents($root . '/include/cacti_version')));
// Use the production gettext fallback and legacy helpers; no function or SQL
// replacements. These explicit settings only disable logging/browser behavior.
$config = ['base_path' => $root, 'library_path' => $root . '/lib', 'is_web' => false, 'poller_id' => 1, 'url_path' => '/fixture/', 'cacti_server_os' => strtolower(PHP_OS_FAMILY),
    'config_options_array' => ['i18n_language_support' => '0', 'i18n_log' => '0', 'selective_debug' => '',
        'log_verbosity' => '0', 'log_destination' => '0', 'path_cactilog' => '/dev/null', 'client_timezone_support' => '0',
        'path_spine' => '', 'reports_allow_ln' => '', 'auth_method' => '0'],
];
$_SESSION = [];
require $root . '/include/global_languages.php';
require $root . '/include/global_arrays.php';
// xml_to_cdef only reads the name from the production CDEF form field schema.
$fields_cdef_edit = ['name' => ['method' => 'textbox']];
$preview_only = false;
$import_debug_info = [];
$import_messages = [];
$database_hostname = 'isolated-fixture';
$database_port = 0;
$database_default = 'isolated-fixture';

function callerAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
    echo "PASS $message\n";
}

$dsn = getenv('KADUPUL_REFERENCE_TEST_DSN');
if ($dsn === false || !str_starts_with($dsn, 'mysql:')) {
    throw new RuntimeException('An explicitly configured native caller probe DSN is required.');
}
$database = new PDO($dsn, getenv('KADUPUL_REFERENCE_TEST_USER') ?: '', getenv('KADUPUL_REFERENCE_TEST_PASSWORD') ?: '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false,
]);
$database_sessions = ["$database_hostname:$database_port:$database_default" => $database];

if (($argv[1] ?? '') === 'writer') {
    $database->exec('USE `' . $argv[2] . '`');
    $database->exec('SET SESSION innodb_lock_wait_timeout=15');
    $database->exec('SET SESSION TRANSACTION ISOLATION LEVEL ' . $argv[3]);
    $case = $argv[4];
    echo "READY\n";
    flush();
    if ($case === 'xml') {
        $cache = [];
        $xml = ['name' => 'Actual waiting XML import', 'items' => [
            'hash_140103' . str_repeat('f', 32) => ['sequence' => '1', 'type' => '5', 'value' => 'hash_050103' . str_repeat('a', 32)],
        ]];
        $result = xml_to_cdef(str_repeat('e', 32), $xml, $cache);
        if ($result === false) {
            callerAssert(($import_debug_info['result'] ?? null) === 'fail' && $cache === [], 'resumed production importer reports failure and does not cache partial parent');
        }
    } else {
        [$table, $operation] = explode(':', $case);
        $row = ['id' => $operation === 'update' ? 1 : 0, 'cdef_id' => 7];
        if ($table === 'cdef_items') {
            $row += ['type' => 5, 'value' => '7', 'sequence' => 1, 'hash' => str_repeat('f', 32)];
            $row['cdef_id'] = $operation === 'owner' ? 7 : 8;
            if ($operation === 'owner') {
                $row['type'] = 1;
                $row['value'] = '1';
            }
        }
        $result = sql_save($row, $table);
    }
    echo $result === false ? "REJECTED\n" : "ACCEPTED\n";
    exit;
}

$schema = 'kadupul_cdef_waiter_' . bin2hex(random_bytes(8));
$created = false;
$process = null;
$pipes = [];
try {
    echo 'SERVER ' . $database->query('SELECT VERSION()')->fetchColumn() . "\n";
    $database->exec("CREATE DATABASE `$schema`");
    $created = true;
    $database->exec("USE `$schema`");
    foreach ([
        'cdef' => "id MEDIUMINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, hash VARCHAR(32) NOT NULL DEFAULT '', `system` MEDIUMINT UNSIGNED NOT NULL DEFAULT 0, name VARCHAR(255) NOT NULL DEFAULT ''",
        'cdef_items' => "id MEDIUMINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, hash VARCHAR(32) NOT NULL DEFAULT '', cdef_id MEDIUMINT UNSIGNED NOT NULL, sequence MEDIUMINT UNSIGNED NOT NULL DEFAULT 0, type TINYINT UNSIGNED NOT NULL, value VARCHAR(150) NOT NULL, INDEX owner (cdef_id,sequence)",
        'graph_templates_item' => 'id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, cdef_id MEDIUMINT UNSIGNED NOT NULL DEFAULT 0, INDEX reference_id (cdef_id)',
        'aggregate_graph_templates_item' => 'id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, cdef_id MEDIUMINT UNSIGNED NULL',
        'aggregate_graphs_graph_item' => 'id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, cdef_id MEDIUMINT UNSIGNED NULL',
    ] as $table => $columns) {
        $database->exec("CREATE TABLE `$table` ($columns) ENGINE=InnoDB");
    }
    cdef_reference_install();
    $cases = ['graph_templates_item:insert', 'graph_templates_item:update', 'aggregate_graph_templates_item:insert',
        'aggregate_graph_templates_item:update', 'aggregate_graphs_graph_item:insert', 'aggregate_graphs_graph_item:update',
        'cdef_items:owner', 'cdef_items:insert', 'cdef_items:update', 'xml'];
    foreach (['READ COMMITTED', 'REPEATABLE READ'] as $isolation) {
        foreach ($cases as $case) {
            foreach ([false, true] as $rollbackOwner) {
                foreach (['cdef_items', 'graph_templates_item', 'aggregate_graph_templates_item', 'aggregate_graphs_graph_item'] as $table) {
                    $database->exec("DELETE FROM `$table`");
                }
                $database->exec("DELETE FROM cdef WHERE id NOT IN (7,8)");
                $database->exec("INSERT INTO cdef (id,hash,name) VALUES (7,'" . str_repeat('a', 32) . "','Target'),(8,'" . str_repeat('b', 32) . "','Owner') ON DUPLICATE KEY UPDATE name=VALUES(name)");
                $table = $case === 'xml' ? 'cdef_items' : explode(':', $case)[0];
                if (str_ends_with($case, ':update')) {
                    $database->exec($table === 'cdef_items' ? "INSERT INTO cdef_items(id,cdef_id,type,value) VALUES (1,8,5,'8')"
                        : "INSERT INTO `$table` (id,cdef_id) VALUES(1,0)");
                }
                $before = $database->query("SELECT * FROM `$table` ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
                $database->beginTransaction();
                $database->exec('DELETE FROM cdef WHERE id=7');
                $process = proc_open(
                    [PHP_BINARY, '-d', 'auto_prepend_file=', '-d', 'zend.exception_ignore_args=1', __FILE__, 'writer', $schema, $isolation, $case],
                    [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
                    $pipes
                );
                if (!is_resource($process)) {
                    throw new RuntimeException('Cannot start actual production second writer.');
                }
                fclose($pipes[0]);
                callerAssert(trim((string) fgets($pipes[1])) === 'READY', "$isolation $case production writer starts");
                $read = [$pipes[1]];
                $write = $except = [];
                callerAssert(stream_select($read, $write, $except, 0, 500000) === 0, "$isolation $case production writer waits for owner decision");
                $rollbackOwner ? $database->rollBack() : $database->commit();
                $output = trim((string) stream_get_contents($pipes[1]));
                $errors = stream_get_contents($pipes[2]);
                fclose($pipes[1]);
                fclose($pipes[2]);
                $exit = proc_close($process);
                $process = null;
                $pipes = [];
                callerAssert($exit === 0 && $errors === '', "$isolation $case production writer exits normally");
                callerAssert(str_ends_with($output, $rollbackOwner ? 'ACCEPTED' : 'REJECTED'), "$isolation $case production writer " . ($rollbackOwner ? 'accepts after rollback' : 'rejects after commit'));
                if (!$rollbackOwner) {
                    callerAssert(
                        $database->query("SELECT * FROM `$table` ORDER BY id")->fetchAll(PDO::FETCH_ASSOC) === $before,
                        "$isolation $case rejected production child statement is atomic"
                    );
                }
            }
        }
    }
    echo "PASS actual production resumed writers probe complete\n";
} finally {
    if ($database->inTransaction()) {
        $database->rollBack();
    }
    if (is_resource($process)) {
        proc_terminate($process);
        foreach ($pipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }
        proc_close($process);
    }
    if ($created) {
        $database->exec("DROP DATABASE `$schema`");
    }
}
