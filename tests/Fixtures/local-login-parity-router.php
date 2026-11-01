<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

// Router for `php -S` used by tests/Symfony/LocalLoginParityTest.php and
// tests/Symfony/BasicLoginParityTest.php. It boots
// the legacy web stack the way include/global.php does for one request, against
// the MariaDB schema named in the environment, then runs the real
// auth_login.php or the real include/auth.php gate. Only presentation (theme
// header, translation) and plugin dispatch are replaced; credentials, lockout,
// sessions, cookies, user_log and cacti_log all run the production code.

$root = dirname(__DIR__, 2);
$path = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
if ($path === '/__health') {
    print 'ready';
    return true;
}

// TEST-ONLY web server identity. `php -S` has no authentication module, so it
// cannot set REMOTE_USER or REDIRECT_REMOTE_USER the way Apache or nginx do
// after authenticating a user. When, and only when, the test process starts
// this router with KADUPUL_PARITY_SERVER_IDENTITY=1, the base64 JSON object in
// the X-Kadupul-Test-Server-Identity request header supplies those two server
// variables. The header itself is removed before the legacy code runs, so the
// legacy code sees a server variable, never a client header. Production code
// has no equivalent; BasicLoginParityTest is the only user.
if (getenv('KADUPUL_PARITY_SERVER_IDENTITY') === '1' && isset($_SERVER['HTTP_X_KADUPUL_TEST_SERVER_IDENTITY'])) {
    $identity = json_decode((string) base64_decode((string) $_SERVER['HTTP_X_KADUPUL_TEST_SERVER_IDENTITY'], true), true, 4, JSON_THROW_ON_ERROR);
    foreach (['REMOTE_USER', 'REDIRECT_REMOTE_USER'] as $variable) {
        if (is_array($identity) && is_string($identity[$variable] ?? null)) {
            $_SERVER[$variable] = $identity[$variable];
        }
    }
}
unset($_SERVER['HTTP_X_KADUPUL_TEST_SERVER_IDENTITY']);

$stateDirectory = (string) getenv('KADUPUL_PARITY_STATE');
$started = hrtime(true);

$database_type = 'mysql';
$database_default = (string) getenv('KADUPUL_PARITY_DB_NAME');
$database_hostname = (string) getenv('KADUPUL_PARITY_DB_HOST');
$database_username = (string) getenv('KADUPUL_PARITY_DB_USER');
$database_password = (string) getenv('KADUPUL_PARITY_DB_PASSWORD');
$database_port = (string) getenv('KADUPUL_PARITY_DB_PORT');
$database_retries = 2;
$database_ssl = false;
$database_ssl_key = '';
$database_ssl_cert = '';
$database_ssl_ca = '';

$config = [
    'base_path' => $root,
    'library_path' => $root . '/lib',
    'include_path' => $root . '/include',
    'url_path' => '/',
    'poller_id' => 1,
    'is_web' => true,
    'connection' => 'online',
    'cacti_server_os' => 'unix',
    'proxy_headers' => [],
    'proxy_trusted_addresses' => [],
    'rrd_maintenance_trusted_uids' => [],
    'rrd_maintenance_trusted_gids' => [],
    'cacti_session_name' => 'Cacti',
    'cookie_options' => ['cookie_httponly' => true, 'cookie_path' => '/', 'use_strict_mode' => true, 'cookie_samesite' => 'Strict'],
];
if (getenv('KADUPUL_PARITY_FLOOR_MS') !== false) {
    $config['auth_login_timing_floor_ms'] = (int) getenv('KADUPUL_PARITY_FLOOR_MS');
}

define('CACTI_VERSION', trim((string) file_get_contents($root . '/include/cacti_version')));
define('CACTI_CLI', false);
define('URL_PATH', '/');
define('CACTI_LOCALE', 'en-US');

require_once $root . '/include/vendor/autoload.php';
require_once $root . '/lib/database.php';
require_once $root . '/lib/functions.php';
require_once $root . '/lib/headers_secure.php';
require_once $root . '/include/global_constants.php';

// Presentation and plugin dispatch: no plugins are installed, so every hook
// returns its argument as api_plugin_hook_function does with none registered.
function __(...$arguments)
{
    $text = (string) array_shift($arguments);

    return $arguments === [] ? $text : vsprintf($text, $arguments);
}

function __esc(...$arguments)
{
    return htmlspecialchars(__(...$arguments), ENT_QUOTES, 'UTF-8');
}

function api_plugin_hook($name) {}

function api_plugin_hook_function($name, $argument = '')
{
    return $argument;
}

// lib/html.php renders the full theme header, which needs the asset and
// permission stack; the login page only needs its title and escaping.
function html_common_header($title, $selectedTheme = '')
{
    print '<title>' . html_escape($title) . '</title>';
}

function html_escape($string)
{
    return $string === null ? null : htmlspecialchars(str_replace('`', '&#96;', (string) $string), ENT_QUOTES | ENT_HTML5, 'UTF-8', false);
}

// The login form's CSRF contract has its own suite (LoginMutationCsrfTest).
function csrf_check($fatal = true)
{
    return true;
}

function csrf_get_tokens()
{
    return 'fixture';
}

require_once $root . '/lib/html_utility.php';
require_once $root . '/lib/html_validate.php';

if (!db_connect_real($database_hostname, $database_username, $database_password, $database_default, $database_type, $database_port, $database_retries)) {
    http_response_code(500);
    print 'database connection failed';
    return true;
}
$config['cacti_db_version'] = CACTI_VERSION;
$config['cacti_version'] = CACTI_VERSION;
// include/global_settings.php is not loaded: no setting falls back to a default.
$settings = [];
$settings_user = [];
define('CACTI_DATE_TIME_FORMAT', 'Y-m-d H:i:s');

cacti_session_start();
require_once $root . '/lib/auth.php';

register_shutdown_function(static function () use ($stateDirectory, $started): void {
    $session = [];
    foreach (['sess_user_id', 'sess_user_credential', 'sess_change_password', 'sess_remember_token', 'sess_cookie_credential'] as $key) {
        if (isset($_SESSION[$key])) {
            $session[$key] = $_SESSION[$key];
        }
    }
    file_put_contents($stateDirectory . '/state.json', json_encode([
        'session' => $session,
        'elapsed_ms' => intdiv(hrtime(true) - $started, 1000000),
    ], JSON_THROW_ON_ERROR));
});

$_SERVER['SCRIPT_NAME'] = $path;
if ($path === '/auth_login.php') {
    require $root . '/auth_login.php';
} elseif ($path === '/gated.php') {
    // include/auth.php requires 'global.php' by include path; the request is
    // already booted above, so resolve it to an empty file.
    set_include_path($stateDirectory . '/include-path');
    $user_auth_realm_filenames = ['gated.php' => 7];
    if (isset($_GET['guest'])) {
        $guest_account = true;
    }
    require $root . '/include/auth.php';
    print 'GATED:' . $_SESSION['sess_user_id'];
} else {
    http_response_code(404);
}

return true;
