<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

// Replaces only installation configuration in a disposable worker root. The
// copied include/auth.php, session functions, database helpers, policy and
// worker are the real production implementations; no identity is admitted here.
$sourceRoot = getenv('KADUPUL_BROWSER_DENIAL_SOURCE_ROOT');
set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if ((error_reporting() & $severity) !== 0) {
        throw new ErrorException('Native utility fixture warning.', 0, $severity, $file, $line);
    }
    return false;
}, E_WARNING | E_NOTICE | E_USER_WARNING | E_USER_NOTICE);
$fixtureRoot = dirname(__DIR__);
if (!is_string($sourceRoot) || !is_file($sourceRoot . '/include/vendor/autoload.php')) {
    throw new RuntimeException('Utility fixture source root is unavailable.');
}
require_once $sourceRoot . '/include/vendor/autoload.php';
require_once $sourceRoot . '/include/global_constants.php';
require_once $sourceRoot . '/lib/database.php';
require_once $sourceRoot . '/lib/functions.php';
require_once $sourceRoot . '/lib/auth.php';
require_once $sourceRoot . '/lib/html_utility.php';
require_once $sourceRoot . '/lib/html.php';
require_once $sourceRoot . '/lib/html_form.php';
require_once $sourceRoot . '/lib/plugins.php';
require_once $sourceRoot . '/lib/variables.php';

$database_hostname = 'utility-worker';
$database_port = '1';
$database_default = 'owned';
$database_total_queries = 0;
$database_sessions = ['utility-worker:1:owned' => new PDO(getenv('KADUPUL_BROWSER_DENIAL_WORKER_DSN'), getenv('KADUPUL_TEST_MYSQL_ADMIN_USER') ?: 'root', getenv('KADUPUL_TEST_MYSQL_ADMIN_PASSWORD') ?: '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION])];
$version = trim((string) file_get_contents($sourceRoot . '/include/cacti_version'));
if (!defined('CACTI_VERSION')) {
    define('CACTI_VERSION', $version);
}
if (!defined('SQL_NO_CACHE')) {
    define('SQL_NO_CACHE', '');
}
if (!defined('CACTI_DATE_TIME_FORMAT')) {
    define('CACTI_DATE_TIME_FORMAT', 'Y-m-d H:i:s');
}
$config = ['base_path' => $fixtureRoot, 'library_path' => $sourceRoot . '/lib', 'include_path' => $sourceRoot . '/include',
    'url_path' => '/fixture/', 'rra_path' => $fixtureRoot . '/rra', 'cacti_server_os' => 'unix', 'is_web' => true, 'poller_id' => 1,
    'connection' => 'online', 'cacti_db_version' => $version, 'cacti_session_name' => 'BrowserRealmDenialFixture',
    'cookie_options' => ['use_cookies' => PHP_SAPI === 'cli-server', 'use_only_cookies' => true, 'use_strict_mode' => false, 'cookie_httponly' => true, 'cookie_samesite' => 'Strict']];
if (in_array(getenv('KADUPUL_BROWSER_DENIAL_CONNECTION'), ['offline', 'recovery'], true)) {
    $config['connection'] = getenv('KADUPUL_BROWSER_DENIAL_CONNECTION');
    $config['poller_id'] = 2;
}
$plugins_integrated = [];
$plugin_hooks = [];
$no_http_header_files = [];
if (getenv('KADUPUL_BROWSER_DENIAL_SESSION_BACKEND') === 'database') {
    require_once $sourceRoot . '/include/session.php';
}
cacti_session_start();
require_once $sourceRoot . '/include/global_languages.php';
require_once $sourceRoot . '/include/global_arrays.php';
require_once $sourceRoot . '/include/global_settings.php';
if (PHP_SAPI === 'cli-server') {
    require_once $sourceRoot . '/lib/headers_secure.php';
    CactiSecureHeaders::emitHeaders();
    require_once $sourceRoot . '/include/csrf.php';
}


// Actual installed internal plugin dispatcher probe, only registered by the test.
function browser_worker_fixture_custom_denied($mode)
{
    $_SESSION['fixture_denial_hook_count'] = ($_SESSION['fixture_denial_hook_count'] ?? 0) + 1;
    if (getenv('KADUPUL_BROWSER_DENIAL_HOOK') === 'reskin') {
        print 'fixture-reskin-denial';
        return OPER_MODE_RESKIN;
    }
    if (getenv('KADUPUL_BROWSER_DENIAL_HOOK') === 'exit') {
        print 'fixture-hook-exit';
        exit;
    }
    if (getenv('KADUPUL_BROWSER_DENIAL_HOOK') === 'tamper') {
        file_put_contents(__DIR__ . '/auth.php', "\n// owned-copy failure injection\n", FILE_APPEND);
    }
    if (getenv('KADUPUL_BROWSER_DENIAL_HOOK') === 'binding') {
        $_SESSION['sess_user_credential'] = str_repeat('b', 64);
    }
    return $mode;
}
