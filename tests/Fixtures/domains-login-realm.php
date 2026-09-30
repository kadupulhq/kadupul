<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

// Runs the real LDAP Domains login code against an in-memory directory and
// SQLite tables. "process" drives domains_login_process() from lib/auth.php.
$root = $argv[1];
$mode = $argv[2];
$scenario = json_decode($argv[3], true, 512, JSON_THROW_ON_ERROR);

define('POLLER_VERBOSITY_LOW', 2);
define('POLLER_VERBOSITY_DEBUG', 5);
define('FILTER_VALIDATE_IS_REGEX', 99999);
define('FILTER_VALIDATE_IS_NUMERIC_ARRAY', 100000);
define('FILTER_VALIDATE_IS_NUMERIC_LIST', 100001);

$events = array();
$_REQUEST = $scenario['request'];

register_shutdown_function(function () {
    echo json_encode(array(
        'events'    => $GLOBALS['events'],
        'error'     => $GLOBALS['error'] ?? null,
        'error_msg' => $GLOBALS['error_msg'] ?? null,
        'user'      => $GLOBALS['result'] ?? null,
    ));
});

function read_config_option($key)
{
    return $GLOBALS['scenario']['config'][$key] ?? '';
}
function cacti_sizeof($value)
{
    return is_array($value) ? count($value) : 0;
}
function cacti_log($message, ...$args)
{
    // One marker per request: the lockout path logs its own failure lines too.
    if (str_starts_with($message, 'LOGIN FAILED') && !in_array('LOG_FAILED', $GLOBALS['events'], true)) {
        $GLOBALS['events'][] = 'LOG_FAILED';
    }
}
function get_client_addr()
{
    return '192.0.2.10';
}
function __(...$args)
{
    return vsprintf((string) $args[0], array_slice($args, 1));
}
function __esc(...$args)
{
    return __(...$args);
}

if ($mode === 'process') {
    require $root . '/lib/html_utility.php';
    require $root . '/lib/auth.php';

    #[AllowDynamicProperties]
    class Ldap
    {
        public function Authenticate()
        {
            $GLOBALS['events'][] = 'BIND';
            $code = $GLOBALS['scenario']['bind'];

            return array('error_num' => $code, 'error_text' => $code === 0 ? 'Authentication Success' : 'Directory detail');
        }

        public function Search()
        {
            $GLOBALS['events'][] = 'SEARCH';
            $code = $GLOBALS['scenario']['search'];

            return array('error_num' => $code, 'error_text' => 'Directory detail', 'dn' => 'uid=' . $this->username . ',dc=example,dc=org');
        }

        public function Getcn()
        {
            $GLOBALS['events'][] = 'CN';
            $response = array('error_num' => 0, 'error_text' => 'Authentication Success');
            if (isset($GLOBALS['scenario']['cn'])) {
                $response['cn'] = $GLOBALS['scenario']['cn'];
            }

            return $response;
        }
    }

    function die_html_input_error($variable = '', $value = '', $message = '')
    {
        $GLOBALS['events'][] = 'INPUT_REJECTED';
        exit;
    }

    $db = new PDO('sqlite::memory:');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec("CREATE TABLE user_auth (id INTEGER, username TEXT, realm INTEGER, full_name TEXT DEFAULT '', email_address TEXT DEFAULT '', must_change_password TEXT DEFAULT '', enabled TEXT DEFAULT 'on', locked TEXT DEFAULT '', lastfail INTEGER DEFAULT 0, failed_attempts INTEGER DEFAULT 0, password TEXT DEFAULT '')");
    foreach (array('user_auth_perms', 'user_auth_realm', 'settings_user', 'settings_tree', 'user_auth_group_members') as $table) {
        $db->exec('CREATE TABLE ' . $table . ' (user_id INTEGER, group_id INTEGER)');
    }
    $db->exec('CREATE TABLE user_domains (domain_id INTEGER, domain_name TEXT, enabled TEXT, defdomain INTEGER, user_id INTEGER)');
    $db->exec('CREATE TABLE user_domains_ldap (domain_id INTEGER, server TEXT, dn TEXT, mode INTEGER, group_require TEXT, cn_full_name TEXT, cn_email TEXT)');
    foreach ($scenario['domains'] as $domain) {
        $db->prepare('INSERT INTO user_domains VALUES (?, ?, ?, 0, ?)')->execute(array($domain['id'], 'Domain ' . $domain['id'], $domain['enabled'], $domain['template'] ?? 0));
        if ($domain['ldap'] ?? true) {
            $db->prepare("INSERT INTO user_domains_ldap VALUES (?, 'ldap.example.org', 'uid=<username>,dc=example,dc=org', 1, '', ?, ?)")->execute(array($domain['id'], $domain['cn_full_name'] ?? '', $domain['cn_email'] ?? ''));
        }
    }
    foreach ($scenario['users'] as $row) {
        $db->prepare('INSERT INTO user_auth (id, username, realm, locked) VALUES (?, ?, ?, ?)')->execute(array($row['id'], $row['username'], $row['realm'], $row['locked'] ?? ''));
    }

    function db_fetch_row_prepared($sql, $params = array())
    {
        $query = $GLOBALS['db']->prepare($sql);
        $query->execute($params);

        return $query->fetch(PDO::FETCH_ASSOC) ?: array();
    }
    function db_fetch_cell_prepared($sql, $params = array())
    {
        $query = $GLOBALS['db']->prepare($sql);
        $query->execute($params);

        return $query->fetchColumn();
    }
    function db_fetch_cell($sql)
    {
        return db_fetch_cell_prepared($sql);
    }
    function db_fetch_assoc($sql)
    {
        return $GLOBALS['db']->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    }
    function db_fetch_assoc_prepared($sql, $params = array())
    {
        $query = $GLOBALS['db']->prepare($sql);
        $query->execute($params);

        return $query->fetchAll(PDO::FETCH_ASSOC);
    }
    function input_validate_input_number($value)
    {
        if (!is_numeric($value)) {
            throw new RuntimeException('Invalid test number');
        }
    }
    function raise_message($id) {}
    function api_plugin_hook_function($hook, $data = null)
    {
        return $data;
    }
    // user_copy() only needs the new user_auth row; permission rows are empty here.
    function sql_save($row, $table, ...$args)
    {
        if ($table !== 'user_auth') {
            return true;
        }
        $GLOBALS['events'][] = 'COPY';
        $id = 100 + (int) $GLOBALS['db']->query('SELECT COUNT(*) FROM user_auth')->fetchColumn();
        $GLOBALS['db']->prepare('INSERT INTO user_auth (id, username, realm, full_name, email_address) VALUES (?, ?, ?, ?, ?)')
            ->execute(array($id, $row['username'], $row['realm'], $row['full_name'], $row['email_address']));

        return $id;
    }
    function db_execute_prepared($sql, $params = array())
    {
        if (str_starts_with(ltrim($sql), 'UPDATE user_auth') && str_contains($sql, 'failed_attempts + 1')) {
            $GLOBALS['events'][] = 'LOCKOUT_COUNT';
        }

        if (str_contains($sql, 'INTO user_log')) {
            return true;
        }

        return $GLOBALS['db']->prepare($sql)->execute($params);
    }

    $realm = 0;
    $error = false;
    $error_msg = '';
    $result = domains_login_process($scenario['username']);
}
