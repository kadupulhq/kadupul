<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

if (PHP_SAPI !== 'cli' || !getenv('KADUPUL_TEST_MYSQL_DSN')) {
    exit(64);
}

$root = dirname(__DIR__, 2);
require $root . '/include/vendor/autoload.php';
require $root . '/include/global_constants.php';
require $root . '/lib/database.php';
require $root . '/lib/plugins.php';
require $root . '/tests/Helpers/PhpSource.php';

foreach (['cacti_sizeof', 'cacti_count', 'clean_up_lines'] as $function) {
    eval(test_php_function_source(file_get_contents($root . '/lib/functions.php'), $function));
}

function cacti_log($message, $output = false, $environment = 'CMDPHP', $level = '')
{
    return true;
}

function cacti_debug_backtrace($entry = '', $html = false, $record = true, $limit = 0, $skip = 0)
{
    return '';
}

$mode = $argv[1] ?? '';
if (!in_array($mode, ['core', 'plugin'], true)) {
    exit(64);
}
parse_str(str_replace(';', '&', substr((string) getenv('KADUPUL_TEST_MYSQL_DSN'), 6)), $dsn);
$prefix = getenv('KADUPUL_TEST_MYSQL_ADMIN_USER') ? 'KADUPUL_TEST_MYSQL_ADMIN_' : 'KADUPUL_TEST_MYSQL_';
$user = getenv($prefix . 'USER') ?: 'root';
$password = getenv($prefix . 'PASSWORD') ?: '';
$database_hostname = (string) $dsn['host'];
$database_port = (string) ($dsn['port'] ?? '3306');
$database_default = 'kddl_' . bin2hex(random_bytes(6));
$server = 'mysql:host=' . $database_hostname . ';port=' . $database_port . ';charset=utf8mb4';
$admin = new PDO($server, $user, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$base = sys_get_temp_dir() . '/kadupul-ddl-charset-' . bin2hex(random_bytes(8));
$ownedSchema = false;
$ownedDirectory = false;

try {
    $admin->exec("CREATE DATABASE `$database_default` CHARACTER SET latin1 COLLATE latin1_swedish_ci");
    $ownedSchema = true;
    if (!mkdir($base, 0700)) {
        throw new RuntimeException('Unable to create fixture directory');
    }
    $ownedDirectory = true;
    $config = ['poller_id' => 1, 'base_path' => $base, 'library_path' => $root . '/lib'];
    $database = db_connect_real($database_hostname, $user, $password, $database_default, 'mysql', $database_port, 2);
    $password = null;
    if (!$database instanceof PDO) {
        throw new RuntimeException('Native legacy connection failed');
    }
    $database->exec('SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci');
    $database->exec("SET SESSION sql_mode = 'STRICT_ALL_TABLES'");
    $session = $database->query('SELECT @@character_set_client AS client_charset, @@character_set_connection AS connection_charset, @@character_set_results AS result_charset, @@sql_mode AS sql_mode')->fetch(PDO::FETCH_ASSOC);
    if ($session['client_charset'] !== 'utf8mb4' || $session['connection_charset'] !== 'utf8mb4' || $session['result_charset'] !== 'utf8mb4' || !str_contains($session['sql_mode'], 'STRICT_ALL_TABLES')) {
        throw new RuntimeException('Native fixture session settings were not applied');
    }

    // The plugin change journal uses the shipped schema, not a permissive stub.
    $schema = file_get_contents($root . '/cacti.sql');
    if ($schema === false || preg_match('/CREATE TABLE `plugin_db_changes` \(.*?\) ENGINE=[^;]+;/s', $schema, $definition) !== 1) {
        throw new RuntimeException('Shipped plugin journal definition not found');
    }
    if ($database->exec($definition[0]) === false) {
        throw new RuntimeException('Shipped plugin journal creation failed');
    }
    $table = 'fixture_' . $mode;
    $data = [
        'type' => 'InnoDB',
        'charset' => 'utf8mb4',
        'collate' => 'utf8mb4_unicode_ci',
        'columns' => [['name' => 'label', 'type' => 'varchar(64)', 'NULL' => false, 'default' => '']],
    ];
    if ($mode === 'plugin') {
        api_plugin_db_table_create('fixture', $table, $data);
    } elseif (db_table_create($table, $data, true, $database) !== true) {
        throw new RuntimeException('Core table creation failed');
    }

    $query = $database->prepare('SELECT CHARACTER_SET_NAME, COLLATION_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?');
    $query->execute([$database_default, $table, 'label']);
    $column = $query->fetch(PDO::FETCH_ASSOC);
    $out = ['mode' => $mode, 'session' => $session, 'column' => $column, 'round_trip' => null, 'insert_error' => null];
    try {
        $insert = $database->prepare("INSERT INTO `$table` (`label`) VALUES (?)");
        if ($insert->execute(["\u{1f600}"]) === false) {
            $out['insert_error'] = $insert->errorCode();
        } else {
            $out['round_trip'] = $database->query("SELECT HEX(label) FROM `$table`")->fetchColumn();
        }
    } catch (PDOException $error) {
        $out['insert_error'] = $error->getCode();
    }
    $out['journal_rows'] = (int) $database->query('SELECT COUNT(*) FROM plugin_db_changes')->fetchColumn();
} finally {
    if ($ownedSchema) {
        $admin->exec("DROP DATABASE `$database_default`");
    }
    if ($ownedDirectory) {
        rmdir($base);
    }
}

echo json_encode($out, JSON_THROW_ON_ERROR), PHP_EOL;
