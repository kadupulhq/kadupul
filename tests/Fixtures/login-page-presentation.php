<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

// Render the shipped entry and CSRF output filter with isolated native services.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
$scenario = json_decode($argv[1] ?? '{}', true, flags: JSON_THROW_ON_ERROR);
$root = dirname(__DIR__, 2);
define('OPER_MODE_NATIVE', 0);
define('OPER_MODE_RESKIN', 2);
define('CACTI_LOCALE', $scenario['locale'] ?? 'en-US');
define('COPYRIGHT_YEARS_SHORT', '2004-2026');
$_SERVER['APP_ENV'] = 'test';
$_SERVER['APP_DEBUG'] = '1';
$_SERVER['SHELL_VERBOSITY'] = $_ENV['SHELL_VERBOSITY'] = '-1';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['SCRIPT_NAME'] = '/monitoring/login.php';
$_SERVER['REQUEST_URI'] = '/monitoring/login.php';
$_SERVER['HTTP_HOST'] = 'localhost';
$config = ['url_path' => '/monitoring/', 'cacti_version' => '1.3.0', 'cacti_server_os' => 'unix'];
$environment = 'plugin-environment';
$debug = 'plugin-debug';
$hooks = [];
$observations = [];
function api_plugin_hook_function($name, $arg = null)
{
    global $scenario, $hooks, $observations, $username;
    $hooks[] = $name;
    if (($scenario['stop'] ?? '') === $name) {
        print '<!--plugin-stopped-->';
        exit;
    }
    if ($name === 'custom_login') {
        if ($scenario['reskin'] ?? false) {
            print '<div id="custom-login">Plugin login</div>';
            return OPER_MODE_RESKIN;
        }
    } elseif ($name === 'login_title') {
        print '<!--login_title-->';
    } elseif ($name === 'login_before') {
        $observations['bootstrap_globals_retained'] = $GLOBALS['environment'] === 'plugin-environment' && $GLOBALS['debug'] === 'plugin-debug';
        $observations['form_open_before_hook'] = str_contains(ob_get_contents(), "<form id='login'");
        print '<input type="hidden" name="plugin_value" value="retained"><!--login_before-->';
        if ($scenario['mutate'] ?? false) {
            $username = 'before <tag> &amp; `';
        }
        return 'IGNORED_HOOK_RETURN';
    } elseif ($name === 'login_realms') {
        $observations['credentials_before_realms'] = str_contains(ob_get_contents(), "id='login_password'");
        print '<!--login_realms-->';
        if ($scenario['mutate'] ?? false) {
            $username = 'realm-phase';
            return ['1' => ['name' => 'Same &amp; <label>', 'selected' => false], '7' => ['name' => 'Same &amp; <label>', 'selected' => true]];
        }
    }
    return $arg;
}
function api_plugin_hook($name): void
{
    global $scenario, $hooks, $observations, $username, $error, $error_msg, $version;
    $hooks[] = $name;
    $observations['submit_before_after_hook'] = str_contains(ob_get_contents(), "type='submit'");
    print '<!--login_after-->';
    if (($scenario['stop'] ?? '') === $name) {
        exit;
    }
    if ($scenario['mutate'] ?? false) {
        $username = '';
        $error = true;
        $error_msg = 'after <script>not markup</script> &amp; `';
        $version = 'hook-version';
    }
}
function set_default_action($default = ''): void {}
function cacti_require_post_actions(array $actions): void {}
function read_config_option($name, $force = false)
{
    return $name === 'auth_method' ? ($GLOBALS['scenario']['method'] ?? 1) : ($name === 'auth_cache_enabled' && ($GLOBALS['scenario']['remember'] ?? false) ? 'on' : '');
}
function auth_get_username(): string
{
    return $GLOBALS['scenario']['username'] ?? '';
}
function get_cacti_version(): string
{
    return '1.3.0';
}
function auth_resource_id($value): int
{
    return (int) $value;
}
function get_nfilter_request_var($name, $default = '')
{
    return $name === 'realm' ? ($GLOBALS['scenario']['realm'] ?? 0) : $default;
}
function die_html_input_error($name): never
{
    throw new RuntimeException('Invalid fixture input');
}
function get_selected_theme(): string
{
    return 'modern';
}
function html_common_header($title): void
{
    print '<meta charset="utf-8"><title>' . html_escape($title) . '</title>';
    foreach (['jquery.js', 'jquery-ui.js', 'js.storage.js'] as $script) {
        print '<script ' . CactiSecureHeaders::getNonceAttribute() . ' src="/include/js/' . $script . '"></script>';
    }
}
function get_current_page(): string
{
    return 'login.php';
}
function html_escape_charset(): string
{
    return 'UTF-8';
}
function html_escape($value): string
{
    return htmlspecialchars(str_replace('`', '&#96;', (string) $value), ENT_QUOTES | ENT_HTML5, 'UTF-8', false);
}
function __($text, ...$args): string
{
    if (CACTI_LOCALE === 'fr-FR') {
        $text = ['User Login' => 'Connexion', 'Username' => 'Utilisateur', 'Password' => 'Mot de passe', 'Login' => 'Connexion', 'Realm' => 'Domaine'][$text] ?? $text;
    }
    return vsprintf($text, $args);
}
function __esc($text): string
{
    return html_escape(__($text));
}
function isempty_request_var($name): bool
{
    return !($GLOBALS['scenario']['remember_checked'] ?? false);
}
function cacti_sizeof($value): int
{
    return is_array($value) ? count($value) : 0;
}
function read_user_setting($name, $default = false)
{
    return 0;
}
function is_realm_allowed($realm): bool
{
    return false;
}
function isset_request_var($name): bool
{
    return false;
}
function kill_session_var($name): void
{
    unset($_SESSION[$name]);
}
function sanitize_uri($uri, $strict = true): string
{
    return $uri;
}
function appendHeaderSuppression($uri): string
{
    return $uri;
}
function get_auth_realms($login = false): ?array
{
    return $GLOBALS['scenario']['domains'] ?? null;
}
function display_output_messages($refresh = false): string
{
    return "''";
}
function get_filter_request_var($name, $filter = 0, $options = [])
{
    return '';
}
require_once $root . '/lib/headers_secure.php';
function csrf_startup(): void
{
    csrf_conf('secret', bin2hex(random_bytes(32)));
    csrf_conf('defer', true);
    csrf_conf('allow-ip', false);
    csrf_conf('rewrite-js', false);
    csrf_conf('frame-breaker', false);
}
$directory = sys_get_temp_dir() . '/login-presentation-' . bin2hex(random_bytes(8));
mkdir($directory, 0700);
session_save_path($directory);
session_start(['use_cookies' => false]);
$_SESSION['fixture_state'] = 'retained';
ob_start();
require $root . '/include/vendor/csrf/csrf-magic.php';
register_shutdown_function(static function () use ($directory): void {
    ob_end_flush(); // Exercise the installed native CSRF rewriting boundary.
    $page = ob_get_clean();
    $token = '';
    if (preg_match('/name=[\'"]__csrf_magic[\'"] value="([^"]+)"/', $page, $match)) {
        $token = $match[1];
    }
    $result = ['page' => $page, 'csp' => CactiSecureHeaders::buildCspPolicy($GLOBALS['scenario']['csp'] ?? 'nonce', CactiSecureHeaders::getNonce(), ''), 'hooks' => $GLOBALS['hooks'], 'observations' => $GLOBALS['observations'], 'session' => $_SESSION, 'token_valid' => $token !== '' && csrf_check_tokens($token)];
    session_abort();
    foreach (glob($directory . '/sess_*') as $file) {
        unlink($file);
    }
    rmdir($directory);
    print json_encode($result, JSON_THROW_ON_ERROR);
});
require $root . '/auth_login.php';
