<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

$root = dirname(__DIR__, 2);
require $root . '/tests/Helpers/PhpSource.php';
$database_hostname = 'group-settings-fixture';
$database_port = 1;
$database_default = 'fixture';
$database_type = 'mysql';
$database_sessions = ['group-settings-fixture:1:fixture' => new PDO(
    (string) getenv('KADUPUL_TEST_MYSQL_DSN'),
    getenv('KADUPUL_TEST_MYSQL_ADMIN_USER') !== false ? getenv('KADUPUL_TEST_MYSQL_ADMIN_USER') : (getenv('KADUPUL_TEST_MYSQL_USER') ?: 'root'),
    getenv('KADUPUL_TEST_MYSQL_ADMIN_PASSWORD') !== false ? getenv('KADUPUL_TEST_MYSQL_ADMIN_PASSWORD') : (getenv('KADUPUL_TEST_MYSQL_PASSWORD') ?: ''),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
)];
$database = $database_sessions['group-settings-fixture:1:fixture'];
$schema = 'group_settings_presence_' . bin2hex(random_bytes(8));
$created = false;
try {
    $database->exec('CREATE DATABASE `' . $schema . '` CHARACTER SET utf8mb4');
    $created = true;
    $database->exec('USE `' . $schema . '`');
    $ddl = file_get_contents($root . '/cacti.sql');
    if (!is_string($ddl)) throw new RuntimeException('Missing shipped schema.');
    foreach (['settings', 'settings_user', 'settings_user_group'] as $table) {
        if (preg_match('/CREATE TABLE `?' . $table . '`?\\s*\\([^;]*;/s', $ddl, $match) !== 1) throw new RuntimeException('Missing settings table.');
        $database->exec($match[0]);
    }
    $database->exec("INSERT INTO settings (name,value) VALUES ('font_method','1')");
    if (($argv[1] ?? '') === 'stored') {
        $insert = $database->prepare('INSERT INTO settings_user_group (group_id,name,value) VALUES (5,?,?)');
        foreach (['hide_disabled' => '', 'min_tree_width' => '0', 'thumbnail_section_preview' => '', 'thumbnail_section_tree_2' => 'on'] as $name => $value) $insert->execute([$name,$value]);
    } elseif (($argv[1] ?? '') === 'unrelated') {
        foreach (['hide_disabled', 'min_tree_width', 'thumbnail_section_preview', 'thumbnail_section_tree_2'] as $name) {
            $database->prepare('INSERT INTO settings_user (user_id,name,value) VALUES (5,?,?)')->execute([$name,'on']);
            $database->prepare('INSERT INTO settings_user_group (group_id,name,value) VALUES (6,?,?)')->execute([$name,'on']);
        }
    } else throw new RuntimeException('Unknown settings scenario.');
$config = ['base_path' => $root, 'library_path' => $root . '/lib', 'include_path' => $root . '/include', 'poller_id' => 1, 'is_web' => false];
$_SESSION = [];
$_REQUEST = ['id' => '5'];
require $root . '/include/global_constants.php';
require $root . '/lib/database.php';
require $root . '/lib/functions.php';
require $root . '/lib/html_utility.php';
function __esc($value, ...$arguments): string { return htmlspecialchars(vsprintf($value, $arguments), ENT_QUOTES); }
function form_start(...$arguments): void {}
function html_start_box(...$arguments): void {}
function html_end_box(...$arguments): void {}
function form_hidden_box(...$arguments): void {}
function form_save_button(...$arguments): void {}
function draw_edit_form(array $form): void { $GLOBALS['observed_fields'] += $form['fields']; }
class CactiSecureHeaders
{
    public static function getNonceAttribute(): string { return ''; }
}
$settings_user = ['general' => [
    'hide_disabled' => ['method' => 'checkbox', 'default' => 'on'],
    'min_tree_width' => ['method' => 'textbox', 'default' => '170'],
    'thumbnail_sections' => ['method' => 'checkbox_group', 'items' => [
        'thumbnail_section_preview' => ['default' => 'on'],
        'thumbnail_section_tree_2' => ['default' => 'on'],
    ]],
]];
$tabs_graphs = ['general' => 'General'];
$GLOBALS['observed_fields'] = [];
$source = file_get_contents($root . '/user_group_admin.php');
if (!is_string($source)) throw new RuntimeException('Missing group editor source.');
eval(test_php_function_source($source, 'user_group_settings_edit'));
ob_start();
user_group_settings_edit('Fixture');
ob_end_clean();
echo json_encode($GLOBALS['observed_fields'], JSON_THROW_ON_ERROR);

} finally {
    if ($created) $database->exec('DROP DATABASE `' . $schema . '`');
}
