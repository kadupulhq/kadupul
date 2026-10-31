<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

$root = dirname(__DIR__, 2);
require $root . '/include/vendor/autoload.php';
require $root . '/tests/Helpers/PhpSource.php';
$database_hostname = 'group-settings-fixture';
$database_port = 1;
$database_default = 'fixture';
$database_type = 'mysql';
$database_sessions = ['group-settings-fixture:1:fixture' => new PDO(
    (string) getenv('KADUPUL_GROUP_SETTINGS_DSN'),
    (string) getenv('KADUPUL_GROUP_SETTINGS_USER'),
    (string) getenv('KADUPUL_GROUP_SETTINGS_PASSWORD'),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
)];
$config = ['base_path' => $root, 'library_path' => $root . '/lib', 'include_path' => $root . '/include', 'poller_id' => 1, 'is_web' => false];
$_SESSION = [];
$_REQUEST = ['id' => '5'];
require $root . '/include/global_constants.php';
require $root . '/lib/database.php';
require $root . '/lib/functions.php';
require $root . '/lib/html_utility.php';
function __esc($value, ...$arguments): string
{
    return htmlspecialchars(vsprintf($value, $arguments), ENT_QUOTES);
}
function form_start(...$arguments): void {}
function html_start_box(...$arguments): void {}
function html_end_box(...$arguments): void {}
function form_hidden_box(...$arguments): void {}
function form_save_button(...$arguments): void {}
function draw_edit_form(array $form): void
{
    $GLOBALS['observed_fields'] += $form['fields'];
}
class CactiSecureHeaders
{
    public static function getNonceAttribute(): string
    {
        return '';
    }
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
