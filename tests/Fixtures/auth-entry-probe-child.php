<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

/*
 * Child process for AuthEntryProbe.php. include/auth.php runs at file scope
 * and may exit, and lib/auth.php calls the global db_* helpers directly, so
 * these stubs must be the first definitions the process sees. It reads a
 * JSON scenario on stdin and prints one JSON result.
 */

$root = dirname(__DIR__, 2);
$scenario = json_decode(stream_get_contents(STDIN), true);

if (!is_array($scenario)) {
    fwrite(STDERR, 'AuthEntryProbe: invalid scenario JSON on stdin');
    exit(1);
}

define('CACTI_VERSION', '1.3.0');
define('POLLER_VERBOSITY_NONE', 1);
define('POLLER_VERBOSITY_LOW', 2);
define('POLLER_VERBOSITY_MEDIUM', 3);
define('POLLER_VERBOSITY_HIGH', 4);
define('POLLER_VERBOSITY_DEBUG', 5);
define('POLLER_VERBOSITY_DEVDBG', 6);
define('OPER_MODE_NATIVE', 0);
define('OPER_MODE_RESKIN', 2);
define('COPYRIGHT_YEARS_SHORT', '2004-2026');

$GLOBALS['probe'] = array(
    'config' => $scenario['config'] ?? array(),
    'users' => $scenario['users'] ?? array(),
    'cache' => $scenario['cache'] ?? array(),
    'realms' => $scenario['realms'] ?? null,
    'groups' => $scenario['groups'] ?? array(),
    'group_members' => $scenario['group_members'] ?? array(),
    'group_realms' => $scenario['group_realms'] ?? array(),
    'request' => $scenario['request'] ?? array(),
    'executed' => array(),
    'events' => array(),
    'log' => array(),
    'headers' => array(),
    'config_writes' => array(),
    'return' => null,
    'page_continued' => false,
    'page' => $scenario['page'] ?? 'probe.php',
);

// The successful-login timing scenario uses the same real PDO transaction
// as production, so the test observes persisted credentials rather than writes.
if (!empty($scenario['credential_database'])) {
    $credential_db = new PDO('sqlite::memory:', null, null, array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
    $credential_db->exec('CREATE TABLE user_auth (id INTEGER PRIMARY KEY, username TEXT, enabled TEXT, realm INTEGER, locked TEXT, password TEXT)');
    $credential_db->exec('CREATE TABLE settings_user (user_id INTEGER, name TEXT, value TEXT, PRIMARY KEY (user_id, name))');
    $insert = $credential_db->prepare('INSERT INTO user_auth VALUES (?, ?, ?, ?, ?, ?)');
    foreach ($GLOBALS['probe']['users'] as $row) {
        $insert->execute(array($row['id'], $row['username'], $row['enabled'], $row['realm'], $row['locked'], $row['password']));
    }
    $database_hostname = 'native_fixture';
    $database_port = 0;
    $database_default = 'auth_contract';
    $database_sessions = array('native_fixture:0:auth_contract' => $credential_db);
}

function probe_normalize_sql(string $sql): string
{
    return trim(preg_replace('/\s+/', ' ', $sql));
}

/**
 * Evaluate the equality filters of a user_auth WHERE clause against the
 * in-memory table. Placeholders bind to $params in order.
 *
 * @return array<int, array<string, mixed>>
 */
function probe_user_rows(string $sql, array $params): array
{
    $where = stristr($sql, 'WHERE');
    $rows = array();

    if ($where === false) {
        return array_values($GLOBALS['probe']['users']);
    }

    preg_match_all("/`?([a-z_]+)`?\s*(!?=)\s*(\?|'[^']*'|\d+)/i", $where, $matches, PREG_SET_ORDER);

    $bind = 0;
    $filters = array();

    foreach ($matches as $match) {
        $filters[] = array($match[1], $match[2], $match[3] === '?' ? $params[$bind++] : trim($match[3], "'"));
    }

    foreach ($GLOBALS['probe']['users'] as $row) {
        foreach ($filters as $filter) {
            $actual = array_key_exists($filter[0], $row) ? (string) $row[$filter[0]] : null;

            if ($filter[1] === '!=' ? $actual === (string) $filter[2] : $actual !== (string) $filter[2]) {
                continue 2;
            }
        }

        $rows[] = $row;
    }

    return $rows;
}

/**
 * Run a query that joins the realm tables against an in-memory SQLite copy of
 * the scenario, so syntax and column names are checked by a real SQL engine
 * rather than by pattern matching. An engine error returns false, as the
 * database layer does.
 *
 * @return mixed
 */
function probe_realm_cell(string $sql, array $params)
{
    static $pdo = null;

    if ($pdo === null) {
        $pdo = new PDO('sqlite::memory:', null, null, array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));

        $pdo->exec('CREATE TABLE user_auth (id INTEGER PRIMARY KEY, username TEXT, enabled TEXT, realm INTEGER, locked TEXT, password TEXT)');
        $pdo->exec('CREATE TABLE user_auth_realm (realm_id INTEGER, user_id INTEGER)');
        $pdo->exec('CREATE TABLE user_auth_group (id INTEGER PRIMARY KEY, enabled TEXT)');
        $pdo->exec('CREATE TABLE user_auth_group_members (group_id INTEGER, user_id INTEGER)');
        $pdo->exec('CREATE TABLE user_auth_group_realm (group_id INTEGER, realm_id INTEGER)');

        $seed = array(
            'INSERT INTO user_auth (id, username, enabled, realm, locked, password) VALUES (?, ?, ?, ?, ?, ?)' => array_map(function (array $row): array {
                return array($row['id'], $row['username'], $row['enabled'], $row['realm'], $row['locked'], $row['password']);
            }, $GLOBALS['probe']['users']),
            'INSERT INTO user_auth_realm (user_id, realm_id) VALUES (?, ?)' => $GLOBALS['probe']['realms'],
            'INSERT INTO user_auth_group (id, enabled) VALUES (?, ?)' => $GLOBALS['probe']['groups'],
            'INSERT INTO user_auth_group_members (group_id, user_id) VALUES (?, ?)' => $GLOBALS['probe']['group_members'],
            'INSERT INTO user_auth_group_realm (group_id, realm_id) VALUES (?, ?)' => $GLOBALS['probe']['group_realms'],
        );

        foreach ($seed as $insert => $rows) {
            $statement = $pdo->prepare($insert);

            foreach ($rows as $row) {
                $statement->execute($row);
            }
        }
    }

    try {
        $statement = $pdo->prepare($sql);
        $statement->execute($params);
    } catch (PDOException $e) {
        return false;
    }

    return $statement->fetchColumn();
}

function read_config_option($name, $force = false)
{
    return $GLOBALS['probe']['config'][$name] ?? '';
}

function set_config_option($name, $value, $remote = false)
{
    $GLOBALS['probe']['config'][$name] = $value;
    $GLOBALS['probe']['config_writes'][] = array($name, $value);
}

function db_fetch_row_prepared($sql, $params = array(), $log = true)
{
    if (strpos($sql, 'FROM user_auth') === false || strpos($sql, 'user_auth_') !== false) {
        return array();
    }

    $rows = probe_user_rows($sql, $params);

    return $rows[0] ?? array();
}

function db_fetch_cell_prepared($sql, $params = array(), $col_name = '', $log = true)
{
    if (strpos($sql, 'FROM user_auth_cache') !== false) {
        foreach ($GLOBALS['probe']['cache'] as $row) {
            if ($row['user_id'] == $params[0] && $row['token'] === $params[1] && $row['hostname'] === $params[2]) {
                return $row['user_id'];
            }
        }

        return false;
    }

    if ($GLOBALS['probe']['realms'] !== null && strpos($sql, 'user_auth_realm') !== false) {
        return probe_realm_cell($sql, $params);
    }

    // SELECT TOP is not MySQL syntax; the real server rejects it.
    if (strpos($sql, 'TOP 1') !== false || !preg_match('/SELECT\s+`?([a-z_]+)`?\s+FROM user_auth\b/i', $sql, $match)) {
        return false;
    }

    $rows = probe_user_rows($sql, $params);

    return isset($rows[0]) ? $rows[0][$match[1]] : false;
}

function db_fetch_cell($sql, $col_name = '', $log = true)
{
    return db_fetch_cell_prepared($sql, array(), $col_name, $log);
}

function db_execute_prepared($sql, $params = array(), $log = true)
{
    $GLOBALS['probe']['executed'][] = array('sql' => probe_normalize_sql($sql), 'params' => $params);
    if (str_contains($sql, 'DELETE FROM user_auth_cache')) {
        $db = new PDO('sqlite::memory:');
        $db->exec('CREATE TABLE user_auth_cache (user_id INTEGER, token TEXT, hostname TEXT)');
        $insert = $db->prepare('INSERT INTO user_auth_cache VALUES (?, ?, ?)');
        foreach ($GLOBALS['probe']['cache'] as $row) {
            $insert->execute(array($row['user_id'], $row['token'], $row['hostname']));
        }
        $db->prepare($sql)->execute($params);
        $GLOBALS['probe']['cache'] = $db->query('SELECT * FROM user_auth_cache')->fetchAll(PDO::FETCH_ASSOC);
    }


    return true;
}

function db_execute($sql, $log = true)
{
    $GLOBALS['probe']['executed'][] = array('sql' => probe_normalize_sql($sql), 'params' => array());

    return true;
}

function db_table_exists($table, $log = true)
{
    return $GLOBALS['scenario']['cache_table'] ?? true;
}

function db_column_exists($table, $column, $log = true)
{
    return true;
}

function cacti_sizeof($array)
{
    return ($array === false || !is_array($array)) ? 0 : sizeof($array);
}

function __($text, ...$args)
{
    return vsprintf($text, $args);
}

function html_escape($string)
{
    return htmlspecialchars((string) $string, ENT_QUOTES, 'UTF-8');
}

function cacti_log($string, $output = false, $environ = 'CMDPHP', $level = '')
{
    if ($level === '') {
        $GLOBALS['probe']['log'][] = $string;
    }
}

function get_cacti_version()
{
    return CACTI_VERSION;
}

function get_current_page($basename = true)
{
    return $GLOBALS['probe']['page'];
}

function api_plugin_hook_function($name, $parm = null)
{
    return $parm;
}

function api_plugin_hook($name) {}

function validate_redirect_url($url = '', $default = 'index.php')
{
    return $default;
}

function get_nfilter_request_var($name, $default = '')
{
    return $GLOBALS['probe']['request'][$name] ?? $default;
}

function get_guest_account()
{
    $guest = (string) ($GLOBALS['probe']['config']['guest_user'] ?? '');

    // Mirrors lib/functions.php: match by username or id, enabled or not.
    foreach ($GLOBALS['probe']['users'] as $row) {
        if ($guest !== '' && ($row['username'] === $guest || (string) $row['id'] === $guest)) {
            return (string) $row['id'];
        }
    }

    return 0;
}

function get_template_account($username = '')
{
    return $GLOBALS['probe']['config']['user_template'] ?? 0;
}

function get_client_addr()
{
    return '192.0.2.10';
}

function kill_session_var($var_name)
{
    unset($_SESSION[$var_name]);
}

if (empty($scenario['real_sessions'])) {
    function cacti_session_start($regenerate = false)
    {
        $GLOBALS['probe']['events'][] = 'session_start';
    }

    function cacti_session_destroy()
    {
        $GLOBALS['probe']['events'][] = 'session_destroy';
        $_SESSION = array();
    }

} else {
    require_once __DIR__ . '/../Helpers/PhpSource.php';
    $functions = file_get_contents($root . '/lib/functions.php');
    foreach (array('cacti_session_start', 'cacti_session_regenerate', 'cacti_session_destroy') as $function) {
        eval(test_php_function_source($functions, $function));
    }
}

function cacti_cookie_logout()
{
    $GLOBALS['probe']['events'][] = 'cookie_logout';
}

function cacti_cookie_session_set($user, $realm, $secret)
{
    $GLOBALS['probe']['events'][] = 'cookie_set';
}

function cacti_cookie_session_logout()
{
    $GLOBALS['probe']['events'][] = 'cookie_session_logout';
}

function html_common_header($title, $selectedTheme = '')
{
    $GLOBALS['probe']['events'][] = 'error_page';
}

function raise_ajax_permission_denied() {}

function db_check_password_length() {}

class CactiSecureHeaders
{
    public static function getNonceAttribute()
    {
        return "nonce='probe'";
    }
}

require_once $root . '/lib/auth.php';

/*
 * include/auth.php loads global.php and global_session.php by relative path
 * and auth_login.php from base_path; point them at stand-ins so the probe
 * controls what runs.
 */
$probe_dir = sys_get_temp_dir() . '/kadupul_auth_entry_' . getmypid() . '_' . bin2hex(random_bytes(4));
mkdir($probe_dir, 0700);
file_put_contents($probe_dir . '/global.php', "<?php\n");
file_put_contents($probe_dir . '/global_session.php', "<?php\n");
file_put_contents($probe_dir . '/auth_login.php', "<?php\n\$GLOBALS['probe']['events'][] = 'login_page';\nexit;\n");
set_include_path($probe_dir);

register_shutdown_function(function () use ($probe_dir): void {
    $session_status = session_status();
    $output = '';

    while (ob_get_level() > 0) {
        $output = ob_get_clean() . $output;
    }

    foreach (array('global.php', 'global_session.php', 'auth_login.php') as $file) {
        unlink($probe_dir . '/' . $file);
    }

    if (!empty($GLOBALS['scenario']['real_sessions'])) {
        session_write_close();
        foreach (glob($probe_dir . '/sess_*') as $session_file) {
            unlink($session_file);
        }
    }
    rmdir($probe_dir);

    foreach (headers_list() as $header) {
        $GLOBALS['probe']['headers'][] = $header;
    }

    print json_encode(array(
        'return' => $GLOBALS['probe']['return'],
        'session_id' => session_id(),
        'session_status' => $session_status,
        'elapsed_seconds' => $GLOBALS['probe']['elapsed_seconds'] ?? null,
        'cache' => $GLOBALS['probe']['cache'],
        'credential_password' => isset($GLOBALS['credential_db']) ? $GLOBALS['credential_db']->query('SELECT password FROM user_auth WHERE id = 42')->fetchColumn() : null,
        'session' => $_SESSION,
        'executed' => $GLOBALS['probe']['executed'],
        'events' => $GLOBALS['probe']['events'],
        'log' => $GLOBALS['probe']['log'],
        'error' => $GLOBALS['error'] ?? null,
        'error_msg' => $GLOBALS['error_msg'] ?? null,
        'config_writes' => $GLOBALS['probe']['config_writes'],
        'page_continued' => $GLOBALS['probe']['page_continued'],
        'output' => $output,
    ));
    $GLOBALS['nativeChildCoverageMarkers'][] = 'auth-entry-result-readback';
});

$config = array(
    'cacti_db_version' => CACTI_VERSION,
    'base_path' => $probe_dir,
    'url_path' => '/kadupul/',
) + ($scenario['runtime_config'] ?? array());

if (!empty($scenario['real_sessions'])) {
    $config['cacti_session_name'] = 'kadupulnative';
    $config['cookie_options'] = array('use_cookies' => false, 'cache_limiter' => '');
    session_save_path($probe_dir);
    session_id('native-old-session-id');
    cacti_session_start();
}
$_SESSION = $scenario['session'] ?? array();

// Ordinary persisted-session fixtures represent a completed login. Tests of
// pre-upgrade sessions explicitly opt out and retain their missing binding.
if (($scenario['bind_session'] ?? true) && isset($_SESSION['sess_user_id'])
    && !array_key_exists('sess_user_credential', $_SESSION)) {
    auth_session_bind_credentials($_SESSION['sess_user_id']);
}

foreach (array('PHP_AUTH_USER', 'REMOTE_USER', 'REDIRECT_REMOTE_USER', 'HTTP_PHP_AUTH_USER', 'HTTP_REMOTE_USER', 'HTTP_REDIRECT_REMOTE_USER', 'HTTP_REFERER') as $key) {
    unset($_SERVER[$key]);
}

foreach (($scenario['server'] ?? array()) as $key => $value) {
    $_SERVER[$key] = $value;
}

if (isset($scenario['cookie'])) {
    $_COOKIE['cacti_remembers'] = $scenario['cookie'];
}

ob_start();

$call = $scenario['call'] ?? array('type' => 'include_auth');

if ($call['type'] === 'include_auth') {
    // Realm -1 lets an authenticated request through without a realm lookup.
    $user_auth_realm_filenames = $scenario['realm_filenames'] ?? array('probe.php' => -1);

    if (!empty($scenario['guest_account'])) {
        $guest_account = true;
    }

    require $root . '/include/auth.php';

    $GLOBALS['probe']['page_continued'] = true;
} elseif (in_array($call['type'], array('check_auth_cookie', 'clear_auth_cookie', 'set_auth_cookie', 'local_auth_login_process', 'auth_login_create_user_from_template', 'cacti_auth_transition'), true)) {
    $started = hrtime(true);
    $GLOBALS['probe']['return'] = call_user_func_array($call['type'], $call['args'] ?? array());
    $GLOBALS['probe']['elapsed_seconds'] = (hrtime(true) - $started) / 1000000000;
} else {
    fwrite(STDERR, 'AuthEntryProbe: unknown call type ' . $call['type']);
    exit(1);
}
