<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

// Whole controller and native HTTP/session/redirect handling. Authentication,
// policy selection and the final editor/list content remain isolated boundaries.
if (PHP_SAPI !== 'cli-server') {
    exit(1);
}
$root = getenv('GRAPH_DENIAL_ROOT');
$directory = getenv('GRAPH_DENIAL_DIRECTORY');
chdir($directory);
session_start();
$_SESSION['sess_user_id'] = 99;
$_SESSION['sentinel'] ??= 'preserved';
$config = ['base_path' => $directory, 'url_path' => '/cacti/'];
$messages = [];
$header_rendered = false;
require $root . '/include/global_constants.php';

require $root . '/tests/Helpers/PhpSource.php';
$functions = file_get_contents($root . '/lib/functions.php');
if ($functions === false) {
    throw new RuntimeException('Cannot read native message and header functions');
}
foreach (['raise_message', 'get_message_level', 'get_message_max_type', 'get_format_message_instance', 'display_output_messages', 'clear_messages', 'top_header'] as $function) {
    eval(test_php_function_source($functions, $function));
}
$auth = file_get_contents($root . '/lib/auth.php');
if ($auth === false) {
    throw new RuntimeException('Cannot read resource ID parser');
}
eval(test_php_function_source($auth, 'auth_resource_id'));
$db = new PDO('sqlite::memory:');
$db->exec('CREATE TABLE graph_local(id INTEGER PRIMARY KEY, host_id INTEGER); INSERT INTO graph_local VALUES(1,1),(2,2);');
function get_request_var(string $name): mixed
{
    return $_REQUEST[$name] ?? '';
}
function get_nfilter_request_var(string $name): mixed
{
    return get_request_var($name);
}
function get_filter_request_var(string $name): int
{
    return (int) get_request_var($name);
}
function isset_request_var(string $name): bool
{
    return isset($_REQUEST[$name]);
}
function isempty_request_var(string $name): bool
{
    return empty($_REQUEST[$name]);
}
function set_request_var(string $name, mixed $value): void
{
    $_REQUEST[$name] = $value;
}
function set_default_action(): void
{
    $_REQUEST['action'] ??= '';
}
function cacti_require_post_actions(array $actions): void {}
function read_config_option(string $key): string
{
    return '0';
}
function is_graph_allowed(mixed $id): bool
{
    return in_array((int) $id, [1,2], true);
}
function is_device_allowed(mixed $id): bool
{
    return (int) $id === 1;
}
function cacti_log(string $message, bool $output = false, string $facility = ''): void
{
    $_SESSION['native_log'][] = [$message, $facility];
}
function cacti_sizeof(mixed $value): int
{
    return is_array($value) ? count($value) : 0;
}
function __(string $text, mixed ...$args): string
{
    return $args ? sprintf($text, ...$args) : $text;
}
function __esc(string $text, mixed ...$args): string
{
    return htmlspecialchars(__($text, ...$args), ENT_QUOTES);
}
function api_plugin_hook_function(string $name, mixed $value): mixed
{
    return $value;
}
function debug_log_return(string $name): string
{
    return '';
}
function debug_log_clear(string $name): void {}
function kill_session_var(string $name): void
{
    unset($_SESSION[$name]);
}
function bottom_footer(): void {}
function native_graph_denial_observe(string $stage): never
{
    header('Content-Type: application/json');
    echo json_encode(['stage' => $stage, 'header_rendered' => $GLOBALS['header_rendered'],
        'message' => json_decode(display_output_messages(), true, 512, JSON_THROW_ON_ERROR),
        'session' => $_SESSION, 'rows' => $GLOBALS['db']->query('SELECT * FROM graph_local ORDER BY id')->fetchAll(PDO::FETCH_ASSOC)], JSON_THROW_ON_ERROR);
    exit;
}
function validate_store_request_vars(mixed ...$arguments): never
{
    native_graph_denial_observe('destination');
}
function db_fetch_cell_prepared(string $sql, array $params = []): mixed
{
    if (str_contains($sql, 'SELECT local_graph_template_graph_id')) {
        native_graph_denial_observe('admitted-editor');
    }
    if (!str_contains($sql, 'SELECT host_id FROM graph_local')) {
        throw new RuntimeException('Unexpected protected query');
    }
    $statement = $GLOBALS['db']->prepare($sql);
    $statement->execute($params);
    return $statement->fetchColumn();
}
function db_fetch_row_prepared(string $sql, array $params = []): never
{
    native_graph_denial_observe('admitted-editor');
}
function db_execute_prepared(mixed ...$arguments): never
{
    throw new RuntimeException('Unexpected mutation');
}
function sql_save(mixed ...$arguments): never
{
    throw new RuntimeException('Unexpected mutation');
}
require $root . '/graphs.php';
