<?php

declare(strict_types=1);

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
$schema = 'kadupul_cdef_callers_' . bin2hex(random_bytes(8));
$created = false;
try {
    echo 'SERVER ' . $database->query('SELECT VERSION()')->fetchColumn() . "\n";
    $database->exec("CREATE DATABASE `$schema`");
    $created = true;
    $database->exec("USE `$schema`");
    foreach ([
        'cdef' => "id MEDIUMINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, hash VARCHAR(32) NOT NULL DEFAULT '', `system` MEDIUMINT UNSIGNED NOT NULL DEFAULT 0, name VARCHAR(255) NOT NULL DEFAULT ''",
        'cdef_items' => "id MEDIUMINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, hash VARCHAR(32) NOT NULL DEFAULT '', cdef_id MEDIUMINT UNSIGNED NOT NULL, sequence MEDIUMINT UNSIGNED NOT NULL DEFAULT 0, type TINYINT UNSIGNED NOT NULL, value VARCHAR(150) NOT NULL, INDEX owner (cdef_id,sequence)",
        'graph_templates_item' => 'id INT UNSIGNED PRIMARY KEY, cdef_id MEDIUMINT UNSIGNED NOT NULL DEFAULT 0, INDEX reference_id (cdef_id)',
        'aggregate_graph_templates_item' => 'id INT UNSIGNED PRIMARY KEY, cdef_id MEDIUMINT UNSIGNED NULL',
        'aggregate_graphs_graph_item' => 'id INT UNSIGNED PRIMARY KEY, cdef_id MEDIUMINT UNSIGNED NULL',
    ] as $table => $columns) {
        $database->exec("CREATE TABLE `$table` ($columns) ENGINE=InnoDB");
    }
    cdef_reference_install();
    $owner = sql_save(['id' => 0, 'hash' => str_repeat('a', 32), 'name' => 'Actual sql_save owner'], 'cdef');
    callerAssert(is_numeric($owner) && (int) $owner > 0, 'actual sql_save creates valid parent');
    $rejected = sql_save(['id' => 0, 'hash' => str_repeat('b', 32), 'cdef_id' => $owner, 'sequence' => 1, 'type' => 5, 'value' => '16777215'], 'cdef_items');
    callerAssert(
        $rejected === false && (int) $database->query('SELECT COUNT(*) FROM cdef_items')->fetchColumn() === 0,
        'actual sql_save reports native missing target refusal without dangling row'
    );
    $cache = [];
    $xml = ['name' => 'Actual missing inherited import', 'items' => [
        'hash_140103' . str_repeat('c', 32) => ['sequence' => '1', 'type' => '5', 'value' => 'hash_050103' . str_repeat('d', 32)],
    ]];
    $result = xml_to_cdef(str_repeat('e', 32), $xml, $cache);
    callerAssert(
        (int) $database->query('SELECT COUNT(*) FROM cdef_items')->fetchColumn() === 0,
        'actual XML importer cannot persist missing inherited reference'
    );
    callerAssert(
        $result === false && ($import_debug_info['result'] ?? null) === 'fail',
        'actual XML importer reports refused child as failure rather than successful parent'
    );
    callerAssert($cache === [], 'failed partial XML import is not cached as successful dependency');
    $validXml = ['name' => 'Actual valid inherited import', 'items' => [
        'hash_140103' . str_repeat('f', 32) => ['sequence' => '1', 'type' => '1', 'value' => '1'],
        'hash_140103' . str_repeat('1', 32) => ['sequence' => '2', 'type' => '5', 'value' => 'hash_050103' . str_repeat('a', 32)],
    ]];
    $validResult = xml_to_cdef(str_repeat('2', 32), $validXml, $cache);
    callerAssert(
        is_array($validResult) && ($import_debug_info['result'] ?? null) === 'success'
        && (int) $database->query('SELECT COUNT(*) FROM cdef_items')->fetchColumn() === 2,
        'actual valid importer persists owned and inherited children and reports success'
    );
    $beforeCache = $cache;
    $partialXml = ['name' => 'Actual later failed import', 'items' => [
        'hash_140103' . str_repeat('3', 32) => ['sequence' => '1', 'type' => '1', 'value' => '1'],
        'hash_140103' . str_repeat('4', 32) => ['sequence' => '2', 'type' => '5', 'value' => 'hash_050103' . str_repeat('d', 32)],
    ]];
    $partialResult = xml_to_cdef(str_repeat('5', 32), $partialXml, $cache);
    callerAssert(
        $partialResult === false && ($import_debug_info['result'] ?? null) === 'fail' && $cache === $beforeCache,
        'actual later child failure restores prior success cache and explicitly reports partial import failure'
    );
    callerAssert(
        (int) $database->query('SELECT COUNT(*) FROM cdef_items')->fetchColumn() === 3,
        'partial import proof retains earlier valid child without claiming distributed rollback'
    );
    echo "PASS native actual production callers probe complete\n";
} finally {
    if ($database->inTransaction()) {
        $database->rollBack();
    }
    if ($created) {
        $database->exec("DROP DATABASE `$schema`");
    }
}
