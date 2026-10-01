<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

/*
 * Runs the shipped form_save() from user_admin.php in a child PHP process
 * with the request, database and message helpers stubbed. The lib/auth.php
 * functions it calls are copied from the shipped file, so the test exercises
 * the real password and session code. The scenario names the request, the
 * user_auth rows, the settings and the session.
 */

require_once __DIR__ . '/PhpSource.php';

if (!function_exists('user_admin_save_probe_run')) {
    /**
     * @param array<string, mixed> $scenario
     *
     * @return array<string, mixed>
     */
    function user_admin_save_probe_run(array $scenario): array
    {
        $root = dirname(__DIR__, 2);
        $auth = file_get_contents($root . '/lib/auth.php');

        $program = <<<'PHP'
$scenario = json_decode(stream_get_contents(STDIN), true);
define('MESSAGE_LEVEL_ERROR', 3);
$_SESSION = $scenario['session'] ?? array();
$_POST = array();
$GLOBALS['request'] = $scenario['request'];
$GLOBALS['users'] = array();
foreach ($scenario['users'] as $row) {
    $GLOBALS['users'][(string) $row['id']] = $row;
}
$GLOBALS['config_options'] = $scenario['config'] ?? array();
$GLOBALS['messages'] = array();
$GLOBALS['executed'] = array();
$GLOBALS['saved'] = null;
$GLOBALS['scenario_templates'] = array_map('strval', $scenario['template_accounts'] ?? array());
function isset_request_var($name) { return isset($GLOBALS['request'][$name]); }
function get_filter_request_var($name, $filter = FILTER_VALIDATE_INT, $options = array()) { return $GLOBALS['request'][$name] ?? ''; }
function get_nfilter_request_var($name, $default = '') { return $GLOBALS['request'][$name] ?? $default; }
function get_request_var($name, $default = '') { return $GLOBALS['request'][$name] ?? $default; }
function form_input_validate($value, $name, $regex, $allow_nulls, $error) { return $value; }
function compat_password_hash($password, $algo, $options = array()) { return 'hash:' . $password; }
function compat_password_verify($password, $hash) { return $hash === 'hash:' . $password; }
function db_fetch_cell_prepared($sql, $params = array(), $col = '', $log = true) {
    if (preg_match('/SELECT\s+([a-z_]+)\s+FROM user_auth\s+WHERE id = \?/i', $sql, $match)) {
        return $GLOBALS['users'][(string) $params[0]][$match[1]] ?? false;
    }
    return false;
}
function db_fetch_row_prepared($sql, $params = array(), $log = true) {
    if (preg_match('/FROM user_auth\s+WHERE id = \?/i', $sql)) {
        $row = $GLOBALS['users'][(string) end($params)] ?? array();
        return str_contains($sql, "enabled = 'on'") && ($row['enabled'] ?? '') !== 'on' ? array() : $row;
    }
    if (str_contains($sql, 'WHERE realm = ? AND username = ? AND id != ?')) {
        foreach ($GLOBALS['users'] as $row) {
            if ((string) $row['realm'] === (string) $params[0] && $row['username'] === $params[1] && (string) $row['id'] !== (string) $params[2]) { return $row; }
        }
    }
    return array();
}
function db_execute_prepared($sql, $params = array(), $log = true) {
    $GLOBALS['executed'][] = array('sql' => trim(preg_replace('/\s+/', ' ', $sql)), 'params' => $params);
    return true;
}
function sql_save($save, $table) {
    $GLOBALS['saved'] = $save;
    $GLOBALS['users'][(string) $save['id']] = $save + ($GLOBALS['users'][(string) $save['id']] ?? array());
    return $save['id'];
}
function read_config_option($name, $force = false) { return $GLOBALS['config_options'][$name] ?? ''; }
function is_template_account($user_id) { return in_array((string) $user_id, $GLOBALS['scenario_templates'], true); }
function is_error_message() { return isset($_SESSION['sess_error_fields']) && cacti_sizeof($_SESSION['sess_error_fields']) > 0; }
function api_plugin_hook_function($name, $parm = null) { return $parm; }
function raise_message($id, $message = '', $level = 0) { $GLOBALS['messages'][] = $id; if ($id === 12) { $_SESSION['sess_error_fields']['username'] = true; } }
function raise_message_javascript($title, $header, $message) { $GLOBALS['messages'][] = $message; }
function cacti_sizeof($array) { return is_array($array) ? count($array) : 0; }
function cacti_count($array) { return is_array($array) ? count($array) : 0; }
function __($text, ...$args) { return vsprintf($text, $args); }
function reset_user_perms($user_id) { $GLOBALS['executed'][] = array('sql' => 'reset_user_perms', 'params' => array($user_id)); }
PHP;

        foreach ($scenario['auth_functions'] ?? array() as $function) {
            $program .= "\n" . test_php_function_source($auth, $function) . "\n";
        }

        $program .= "\n" . test_php_function_source(file_get_contents($root . '/user_admin.php'), 'form_save') . "\n";
        $program .= <<<'PHP'
form_save();
print json_encode(array(
    'session' => $_SESSION,
    'saved' => $GLOBALS['saved'],
    'messages' => $GLOBALS['messages'],
    'executed' => $GLOBALS['executed'],
    'users' => $GLOBALS['users'],
));
PHP;

        $pipes = array();
        $process = proc_open(
            array(PHP_BINARY, '-d', 'display_errors=stderr', '-d', 'error_reporting=' . (E_ALL & ~E_DEPRECATED), '-r', $program),
            array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w')),
            $pipes
        );

        fwrite($pipes[0], json_encode($scenario));
        fclose($pipes[0]);

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);

        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        $decoded = json_decode((string) $stdout, true);

        if (!is_array($decoded) || $stderr !== '') {
            throw new RuntimeException('child process failed: ' . $stdout . $stderr);
        }

        return $decoded;
    }
}
