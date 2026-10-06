<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

// Child process for tests/Unit/Forms/LegacyFormGoldenTest.php. It loads the
// libraries in the order include/global.php does, with the real database
// layer on a connection that answers each query from fixture rows, then
// either calls draw_edit_form() or runs a whole page with header=false. A
// query that no fixture row matches returns no rows, which is what a fresh
// install returns for an object that does not exist yet.
//
// The clock is frozen at 2026-06-15 12:34:56 UTC: every application file the
// child includes is loaded with its calls to time(), date() and the other
// clock functions pointed at the frozen time. The output is then recorded as
// printed, except for what still differs between runs or machines:
// - the CSP nonce becomes <NONCE> and csrf-magic tokens become <CSRF>;
// - the emitted cactiVersion must match the current release, then becomes <VERSION>;
// - the checkout and the scratch directory become <ROOT> and <DIR>, also
//   where they appear HTML-escaped, and the machine name in the default
//   Server Base URL becomes <HOST>;
// - debug backtrace lines are left out of the log, since they name source
//   lines.
// The time zone, the rand() seed, ext-ldap and the php.ini values the test
// passes are fixed instead of normalized.

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

[, $root, $directory] = $argv;
$directory = realpath($directory);

// The goldens depend on extensions composer.json requires, such as gd for the
// report formats. ext-ldap has a stand-in below.
$missing = array();
foreach (array_keys(json_decode(file_get_contents($root . '/composer.json'), true, 512, JSON_THROW_ON_ERROR)['require']) as $package) {
    if (str_starts_with($package, 'ext-') && $package !== 'ext-ldap' && !extension_loaded(substr($package, 4))) {
        $missing[] = $package;
    }
}
if ($missing) {
    fwrite(STDERR, 'The form goldens need ' . implode(', ', $missing) . ' in ' . PHP_BINARY . PHP_EOL);
    exit(3);
}

const LEGACY_FORM_GOLDEN_NOW = 1781526896;

function legacy_form_golden_time()
{
    return LEGACY_FORM_GOLDEN_NOW;
}

function legacy_form_golden_microtime($as_float = false)
{
    return $as_float ? (float) LEGACY_FORM_GOLDEN_NOW : '0.00000000 ' . LEGACY_FORM_GOLDEN_NOW;
}

function legacy_form_golden_date($format, $timestamp = null)
{
    return date($format, $timestamp ?? LEGACY_FORM_GOLDEN_NOW);
}

function legacy_form_golden_gmdate($format, $timestamp = null)
{
    return gmdate($format, $timestamp ?? LEGACY_FORM_GOLDEN_NOW);
}

function legacy_form_golden_idate($format, $timestamp = null)
{
    return idate($format, $timestamp ?? LEGACY_FORM_GOLDEN_NOW);
}

function legacy_form_golden_getdate($timestamp = null)
{
    return getdate($timestamp ?? LEGACY_FORM_GOLDEN_NOW);
}

function legacy_form_golden_localtime($timestamp = null, $associative = false)
{
    return localtime($timestamp ?? LEGACY_FORM_GOLDEN_NOW, $associative);
}

function legacy_form_golden_strtotime($datetime, $base = null)
{
    return strtotime($datetime, $base ?? LEGACY_FORM_GOLDEN_NOW);
}

// Arguments left out default to the current hour, minute and so on.
function legacy_form_golden_mktime(...$arguments)
{
    $now = explode(',', date('G,i,s,n,j,Y', LEGACY_FORM_GOLDEN_NOW));
    for ($i = 0; $i < 6; $i++) {
        $arguments[$i] = (int) ($arguments[$i] ?? $now[$i]);
    }

    return mktime(...$arguments);
}

function legacy_form_golden_gmmktime(...$arguments)
{
    $now = explode(',', gmdate('G,i,s,n,j,Y', LEGACY_FORM_GOLDEN_NOW));
    for ($i = 0; $i < 6; $i++) {
        $arguments[$i] = (int) ($arguments[$i] ?? $now[$i]);
    }

    return gmmktime(...$arguments);
}

function legacy_form_golden_date_create($datetime = 'now', $timezone = null)
{
    $date = (new DateTime('@' . LEGACY_FORM_GOLDEN_NOW))->setTimezone($timezone ?? new DateTimeZone(date_default_timezone_get()));

    return $datetime === 'now' || $datetime === '' ? $date : $date->modify($datetime);
}

function legacy_form_golden_date_create_immutable($datetime = 'now', $timezone = null)
{
    return DateTimeImmutable::createFromMutable(legacy_form_golden_date_create($datetime, $timezone));
}

function legacy_form_golden_uniqid($prefix = '', $more_entropy = false)
{
    static $count = 0;

    return $prefix . sprintf('%08x%05x', LEGACY_FORM_GOLDEN_NOW, ++$count) . ($more_entropy ? '.00000000' : '');
}

/** Point each call to a clock function at its frozen stand-in. */
function legacy_form_golden_freeze($code)
{
    static $clock = array(
        'time', 'microtime', 'date', 'gmdate', 'idate', 'getdate', 'localtime', 'strtotime', 'mktime', 'gmmktime',
        'date_create', 'date_create_immutable', 'uniqid',
    );
    static $not_a_call = array(T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NEW, T_CONST);

    $tokens = PhpToken::tokenize($code);
    $previous = null;
    $frozen = '';
    foreach ($tokens as $index => $token) {
        $name = strtolower(ltrim($token->text, '\\'));
        if (($token->is(T_STRING) || $token->is(T_NAME_FULLY_QUALIFIED)) && in_array($name, $clock, true) && !($previous && $previous->is($not_a_call))) {
            $next = $index + 1;
            while (isset($tokens[$next]) && $tokens[$next]->isIgnorable()) {
                $next++;
            }
            if (isset($tokens[$next]) && $tokens[$next]->text === '(') {
                $frozen .= '\\legacy_form_golden_' . $name;
                $previous = $token;
                continue;
            }
        }
        $frozen .= $token->text;
        if (!$token->isIgnorable()) {
            $previous = $token;
        }
    }

    return $frozen;
}

/**
 * Stands in for the file:// wrapper. Application PHP files opened for include
 * are served frozen; everything else, vendor code included, passes through.
 */
final class LegacyFormGoldenFiles
{
    // STREAM_OPEN_FOR_INCLUDE, which PHP does not expose to scripts.
    private const OPEN_FOR_INCLUDE = 0x80;

    public static $root;
    public static int $transformedIncludes = 0;
    public $context;
    private $handle;

    private static function real(callable $operation)
    {
        stream_wrapper_restore('file');
        try {
            return $operation();
        } finally {
            stream_wrapper_unregister('file');
            stream_wrapper_register('file', self::class);
        }
    }

    public function stream_open($path, $mode, $options, &$opened_path)
    {
        return self::real(function () use ($path, $mode, $options) {
            $real = realpath($path);
            if (($options & self::OPEN_FOR_INCLUDE) && $real !== false && str_ends_with($real, '.php')
                && str_starts_with($real, self::$root . '/') && !str_starts_with($real, self::$root . '/include/vendor/')) {
                self::$transformedIncludes++;
                $this->handle = fopen('php://memory', 'r+');
                fwrite($this->handle, legacy_form_golden_freeze(file_get_contents($real)));
                rewind($this->handle);

                return true;
            }
            $this->handle = ($options & STREAM_REPORT_ERRORS) ? fopen($path, $mode, false, $this->context) : @fopen($path, $mode, false, $this->context);

            return $this->handle !== false;
        });
    }

    public function stream_read($count)
    {
        return fread($this->handle, $count);
    }

    public function stream_write($data)
    {
        return fwrite($this->handle, $data);
    }

    public function stream_eof()
    {
        return feof($this->handle);
    }

    public function stream_tell()
    {
        return ftell($this->handle);
    }

    public function stream_seek($offset, $whence)
    {
        return fseek($this->handle, $offset, $whence) === 0;
    }

    public function stream_flush()
    {
        return fflush($this->handle);
    }

    public function stream_close()
    {
        fclose($this->handle);
    }

    public function stream_stat()
    {
        return fstat($this->handle);
    }

    public function stream_lock($operation)
    {
        return $operation === 0 ? true : flock($this->handle, $operation);
    }

    public function stream_truncate($size)
    {
        return ftruncate($this->handle, $size);
    }

    public function stream_set_option($option, $value, $extra)
    {
        return false;
    }

    public function stream_cast($as)
    {
        return $this->handle;
    }

    public function stream_metadata($path, $option, $value)
    {
        return self::real(function () use ($path, $option, $value) {
            return match ($option) {
                STREAM_META_TOUCH => touch($path, ...$value),
                STREAM_META_ACCESS => chmod($path, $value),
                STREAM_META_OWNER, STREAM_META_OWNER_NAME => chown($path, $value),
                STREAM_META_GROUP, STREAM_META_GROUP_NAME => chgrp($path, $value),
                default => false,
            };
        });
    }

    public function url_stat($path, $flags)
    {
        return self::real(function () use ($path, $flags) {
            // PHP reports a failed stat itself unless the caller asked for quiet.
            return ($flags & STREAM_URL_STAT_LINK) ? @lstat($path) : @stat($path);
        });
    }

    public function unlink($path)
    {
        return self::real(fn() => unlink($path));
    }

    public function rename($from, $to)
    {
        return self::real(fn() => rename($from, $to));
    }

    public function mkdir($path, $mode, $options)
    {
        return self::real(fn() => mkdir($path, $mode, (bool) ($options & STREAM_MKDIR_RECURSIVE)));
    }

    public function rmdir($path, $options)
    {
        return self::real(fn() => rmdir($path));
    }

    public function dir_opendir($path, $options)
    {
        $this->handle = self::real(fn() => opendir($path));

        return $this->handle !== false;
    }

    public function dir_readdir()
    {
        return readdir($this->handle);
    }

    public function dir_rewinddir()
    {
        rewinddir($this->handle);

        return true;
    }

    public function dir_closedir()
    {
        closedir($this->handle);

        return true;
    }
}

LegacyFormGoldenFiles::$root = $root;
// Native presentation coverage executes the original file bytes and clock.
// Existing golden recordings retain their historical patched-clock mode.
if (!defined('PRESENTATION_PAGE_NATIVE')) {
    stream_wrapper_unregister('file');
    stream_wrapper_register('file', LegacyFormGoldenFiles::class);
}
// '<DIR>' in a scenario stands for this run's directory, where it may create files.
$scenario = json_decode(strtr(file_get_contents($directory . '/scenario.json'), array('<DIR>' => $directory)), true, 512, JSON_THROW_ON_ERROR);
foreach ($scenario['files'] ?? array() as $file) {
    if (!is_dir(dirname($directory . '/' . $file))) {
        mkdir(dirname($directory . '/' . $file), 0700, true);
    }
    file_put_contents($directory . '/' . $file, '');
}
// Every scenario starts untranslated, with the log in its own directory.
$scenario += array('settings' => array(), 'db' => array(), 'session' => array(), 'request' => array());
$scenario['settings'] += array('i18n_language_support' => '0', 'path_cactilog' => $directory . '/cacti.log', 'log_destination' => '1');
$scenario['session'] += array('sess_user_id' => 1);

final class LegacyFormGoldenConnection
{
    public function prepare($sql)
    {
        return new LegacyFormGoldenStatement($sql);
    }

    public function inTransaction()
    {
        return false;
    }

    public function errorCode()
    {
        return '00000';
    }

    public function errorInfo()
    {
        return array('00000', null, null);
    }

    public function lastInsertId()
    {
        return '0';
    }

    // Matches the MySQL escaping db_qstr() falls back to.
    public function quote($value)
    {
        return "'" . str_replace(array('\\', "\0", "\n", "\r", "'", '"', "\x1a"), array('\\\\', '\\0', '\\n', '\\r', "\\'", '\\"', '\\Z'), (string) $value) . "'";
    }
}

final class LegacyFormGoldenStatement
{
    private $rows = array();

    public function __construct(private $sql) {}

    public function execute($params = null)
    {
        $this->rows = legacy_form_golden_rows($this->sql, $params ?? array());

        return true;
    }

    public function errorCode()
    {
        return '00000';
    }

    public function errorInfo()
    {
        return array('00000', null, null);
    }

    public function rowCount()
    {
        return count($this->rows);
    }

    public function fetchAll($mode = PDO::FETCH_ASSOC)
    {
        $rows = $this->rows;
        $this->rows = array();

        if ($mode === PDO::FETCH_BOTH) {
            return array_map(function ($row) {
                return $row + array_values($row);
            }, $rows);
        }

        return $rows;
    }

    public function fetch($mode = PDO::FETCH_ASSOC)
    {
        $row = array_shift($this->rows);

        return $row ?? false;
    }

    public function fetchColumn($column = 0)
    {
        $row = array_shift($this->rows);

        return $row === null ? false : array_values($row)[$column];
    }

    public function closeCursor()
    {
        return true;
    }
}

/**
 * Settings come from the scenario's name map, a table always exists, and the
 * signed-in user holds every realm, as an administrator of a fresh install
 * does. Everything else comes from the scenario's 'db' rules, matched by a
 * substring of the whitespace-collapsed SQL and, when a rule lists them, by
 * its bound parameters. The first matching rule wins.
 */
function legacy_form_golden_rows($sql, $params)
{
    global $scenario;

    $normalized = trim(preg_replace('/\s+/', ' ', $sql));
    $params = array_map('strval', array_values($params));
    $settings = $scenario['settings'];

    if (preg_match('/^SELECT (COUNT\(\*\)|value) FROM settings WHERE name = \?$/i', $normalized, $match)) {
        if (strtoupper($match[1]) === 'VALUE') {
            return array_key_exists($params[0], $settings) ? array(array('value' => $settings[$params[0]])) : array();
        }

        return array(array('COUNT(*)' => array_key_exists($params[0], $settings) ? '1' : '0'));
    }
    if (str_starts_with($normalized, 'SELECT name, value FROM settings WHERE name IN (')) {
        $rows = array();
        foreach ($params as $name) {
            if (array_key_exists($name, $settings)) {
                $rows[] = array('name' => $name, 'value' => $settings[$name]);
            }
        }

        return $rows;
    }
    if (preg_match("/^SHOW TABLES LIKE '([^']+)'$/", $normalized, $match)) {
        return array(array('Tables_in_cacti' => $match[1]));
    }
    if (str_starts_with($normalized, 'SELECT realm_id FROM user_auth_realm WHERE user_id = ? AND realm_id = ?')) {
        return array(array('realm_id' => $params[1]));
    }

    foreach ($scenario['db'] as $rule) {
        if (strpos($normalized, $rule['sql']) === false) {
            continue;
        }
        if (array_key_exists('params', $rule) && array_map('strval', $rule['params']) !== $params) {
            continue;
        }

        return array_map(function ($row) use ($normalized) {
            return legacy_form_golden_project($normalized, $row);
        }, $rule['rows']);
    }

    // An aggregate without GROUP BY returns one row even when nothing matches.
    if (preg_match('/^SELECT ((?:COUNT|MAX|MIN|SUM)\(.*?)\s+FROM\s/i', $normalized, $match) && stripos($normalized, ' GROUP BY ') === false) {
        $row = array();
        foreach (explode(',', $match[1]) as $expression) {
            $parts = preg_split('/\s+AS\s+/i', trim($expression));
            $row[trim(end($parts), '`')] = stripos($parts[0], 'COUNT(') === 0 ? '0' : null;
        }

        return array($row);
    }

    // While writing a scenario, LEGACY_FORM_GOLDEN_DISCOVER=1 lists the
    // queries that fell through to the empty result.
    if (getenv('LEGACY_FORM_GOLDEN_DISCOVER')) {
        fwrite(STDERR, 'MISS ' . $normalized . ' ' . json_encode($params) . PHP_EOL);
    }

    return array();
}

/**
 * A rule's row may be a whole table row. When the query selects plain
 * columns, optionally renamed, or quoted literals, return just those, in that
 * order, so one row serves every query on its table.
 */
function legacy_form_golden_project($sql, $row)
{
    if (!preg_match('/^SELECT (?:DISTINCT )?(.+?) FROM /i', $sql, $match)) {
        return $row;
    }
    $projected = array();
    foreach (explode(',', $match[1]) as $item) {
        if (preg_match("/^\s*'([^']*)'\s+AS\s+`?(\w+)`?\s*$/i", $item, $part)) {
            $projected[$part[2]] = $part[1];
        } elseif (preg_match('/^\s*(?:\w+\.)?`?(\w+)`?(?:\s+AS\s+`?(\w+)`?)?\s*$/i', $item, $part) && array_key_exists($part[1], $row)) {
            $projected[$part[2] ?? $part[1]] = $row[$part[1]];
        } else {
            return $row;
        }
    }

    return $projected;
}

// Formatted dates and log lines use the process zone. Pages that add a random
// cache buster to a URL draw it from rand(), which this seed fixes.
date_default_timezone_set('UTC');
mt_srand(20260930);
putenv('TZ=UTC');
setlocale(LC_CTYPE, 'en_US.UTF-8');

// Warnings are part of today's behaviour, recorded without file and line so
// moving code does not change a golden. Deprecations are not: they differ
// between the PHP versions main supports.
$diagnostics = array();
set_error_handler(function ($level, $message) use (&$diagnostics) {
    // An @-silenced call stays silent, as it is in production.
    if (!(error_reporting() & $level)) {
        return true;
    }
    if ($level !== E_DEPRECATED && $level !== E_USER_DEPRECATED) {
        $diagnostics[] = $level . ': ' . $message;
    }

    return true;
});

// The page scenarios run as a web request would; CACTI_CLI follows suit.
require $root . '/include/runtime.php';
define('CACTI_VERSION', trim(file_get_contents($root . '/include/cacti_version')));
define('CACTI_CLI', false);
define('CACTI_DOCUMENTATION_TOC', 'docs/Table-of-Contents.html');

$database_type = 'mysql';
// Nothing listens here, so a path that bypasses the fixture connection fails.
$database_default = 'legacy_form_golden';
$database_hostname = '127.0.0.1';
$database_username = 'nobody';
$database_password = 'nothing';
$database_port = '1';
$database_retries = 2;
$database_ssl = false;
$cacti_session_name = 'Cacti';
$disable_log_rotation = false;
$no_http_header_files = array();
$colors = array();
$config = array(
    'rrd_maintenance_trusted_uids' => array(),
    'rrd_maintenance_trusted_gids' => array(),
    'proxy_headers' => array(),
    'poller_id' => 1,
    'cacti_server_os' => 'unix',
    'php_snmp_support' => false,
    'DEBUG_READ_CONFIG_OPTION' => false,
    'DEBUG_READ_CONFIG_OPTION_DB_OPEN' => false,
    'DEBUG_SQL_CMD' => false,
    'DEBUG_SQL_FLOW' => false,
    'DEBUG_SQL_CONNECT' => false,
    'url_path' => '/',
    'base_path' => $root,
    'library_path' => $root . '/lib',
    'include_path' => $root . '/include',
    'rra_path' => $root . '/rra',
    'scripts_path' => $root . '/scripts',
    'resource_path' => $root . '/resource',
    'connection' => 'online',
    'is_web' => true,
    'cacti_session_name' => $cacti_session_name,
    'cookie_options' => array(),
);
define('URL_PATH', '/');

require $root . '/lib/database.php';
require $root . '/lib/functions.php';
require $root . '/lib/headers_secure.php';
require $root . '/include/global_constants.php';
require $root . '/lib/html.php';
require $root . '/lib/html_utility.php';
require $root . '/lib/html_validate.php';

$database_sessions = array($database_hostname . ':' . $database_port . ':' . $database_default => new LegacyFormGoldenConnection());
$config['cacti_db_version'] = CACTI_VERSION;

// Use a real external secret beside the scenario, outside the served checkout.
$config['path_csrf_web_root'] = $root;
$config['path_csrf_secret'] = $directory . '/csrf-secret.php';
$fixtureCsrfSecret = bin2hex(random_bytes(32));
if (file_put_contents($config['path_csrf_secret'], $fixtureCsrfSecret) !== strlen($fixtureCsrfSecret)) {
    throw new RuntimeException('Unable to create the form fixture CSRF secret');
}
session_save_path($directory);
session_name($cacti_session_name);
session_start();
$_SESSION = $scenario['session'];
$_COOKIE = array();
$_GET = $scenario['request'];
$_POST = array();
$_REQUEST = $_GET;
$page = $scenario['page'] ?? 'form.php';
$_SERVER['SCRIPT_NAME'] = '/' . $page;
$_SERVER['PHP_SELF'] = '/' . $page;
$_SERVER['SCRIPT_FILENAME'] = $root . '/' . $page;
$_SERVER['REQUEST_URI'] = '/' . $page . ($_GET ? '?' . http_build_query($_GET) : '');
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_TIME'] = LEGACY_FORM_GOLDEN_NOW;
$_SERVER['REQUEST_TIME_FLOAT'] = (float) LEGACY_FORM_GOLDEN_NOW;
$_SERVER['SERVER_NAME'] = 'kadupul.example';
$_SERVER['HTTP_HOST'] = 'kadupul.example';
unset($_SERVER['HTTP_REFERER'], $_SERVER['argv'], $_SERVER['argc']);

prime_common_config_settings();
define('CACTI_DATE_TIME_FORMAT', date_time_format());

// composer.json requires ext-ldap, and the LDAP choices in the
// authentication method list depend on it. A PHP build without it gets a
// stand-in so the list matches a supported install.
if (!function_exists('ldap_connect')) {
    function ldap_connect(...$arguments)
    {
        throw new LogicException('LDAP is not available in the form golden harness');
    }
}

require $root . '/include/global_languages.php';
require $root . '/lib/auth.php';
require $root . '/lib/plugins.php';
require $root . '/include/plugins.php';
require $root . '/include/global_arrays.php';
require $root . '/include/global_settings.php';
require $root . '/include/global_form.php';
require $root . '/lib/html_form.php';
require $root . '/lib/html_filter.php';
require $root . '/lib/variables.php';
require $root . '/lib/mib_cache.php';
require $root . '/lib/poller.php';
require $root . '/lib/snmpagent.php';
require $root . '/lib/aggregate.php';
require $root . '/lib/api_automation.php';
// Opened before csrf-magic adds its rewriting buffer, so the captured output
// is what that buffer hands to the web server.
ob_start();
$capture_level = ob_get_level();
register_shutdown_function(function () use ($root, $directory, $capture_level, &$diagnostics) {
    while (ob_get_level() > $capture_level) {
        ob_end_flush();
    }
    $html = ob_get_clean();
    $replace = array(
        CactiSecureHeaders::getNonce() => '<NONCE>',
        "var cactiVersion='" . CACTI_VERSION . "';" => "var cactiVersion='<VERSION>';",
        $directory => '<DIR>',
        $root => '<ROOT>',
        htmlspecialchars($directory, ENT_QUOTES) => '<DIR>',
        htmlspecialchars($root, ENT_QUOTES) => '<ROOT>',
        // The default Server Base URL names the machine.
        'http://' . gethostname() . '/' => 'http://<HOST>/',
    );
    $log = array();
    if (is_file($directory . '/cacti.log')) {
        foreach (file($directory . '/cacti.log', FILE_IGNORE_NEW_LINES) as $line) {
            // A debug backtrace names source lines, which any edit moves.
            if (!str_contains($line, ' Backtrace: ')) {
                $log[] = $line;
            }
        }
    }
    $session = array();
    foreach (array('form_change_actions', 'form_click_actions', 'sess_error_fields', 'sess_field_values') as $key) {
        if (isset($_SESSION[$key])) {
            $session[$key] = $_SESSION[$key];
        }
    }
    $result = array('html' => $html, 'session' => $session, 'diagnostics' => $diagnostics, 'log' => $log);
    array_walk_recursive($result, function (&$value) use ($replace) {
        if (is_string($value)) {
            // A csrf-magic token is an HMAC of the session id and the time,
            // followed by that time.
            $value = preg_replace('/\b(sid|cookie|key|user|ip):[0-9a-f]{40,128},[0-9]+/', '$1:<CSRF>', strtr($value, $replace));
        }
    });
    if (defined('PRESENTATION_PAGE_NATIVE') && isset($GLOBALS['nativePresentationObserver'])) {
        ($GLOBALS['nativePresentationObserver'])($result);
    }
    echo json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
});

if (class_exists('Composer\\Autoload\\ClassLoader', false)
    && (!defined('PRESENTATION_PAGE_NATIVE') || isset(Composer\Autoload\ClassLoader::getRegisteredLoaders()[$root . '/include/vendor']))) {
    throw new RuntimeException('Composer loaded before the production CSRF boundary');
}
require $root . '/include/csrf.php';
require $root . '/include/vendor/autoload.php';
cacti_require_post_actions(array('save', 'update_data', 'changepassword'));
api_plugin_hook('config_insert');
$config['cacti_version'] = CACTI_VERSION;

// include/auth.php leaves the signed-in user here.
$current_user = db_fetch_row_prepared('SELECT * FROM user_auth WHERE id = ?', array($_SESSION['sess_user_id']));

// Native page producers may supply an actual isolated database port after
// bootstrap. Historical golden recordings keep their original connection.
if (defined('PRESENTATION_PAGE_NATIVE') && isset($GLOBALS['nativePresentationBootstrap'])) {
    if (!$GLOBALS['nativePresentationBootstrap'] instanceof Closure) {
        throw new RuntimeException('Native presentation bootstrap must be a Closure');
    }
    ($GLOBALS['nativePresentationBootstrap'])();
}

if (isset($scenario['form'])) {
    // A few methods call helpers from libraries their pages include.
    foreach ($scenario['require'] ?? array() as $library) {
        require_once $root . '/' . $library;
    }
    $exception_recorded = false;
    try {
        draw_edit_form($scenario['form']);
    } catch (Throwable $exception) {
        $expected = $scenario['expected_exception'] ?? null;
        if ($expected === null || get_class($exception) !== $expected['class'] || $exception->getMessage() !== $expected['message']) {
            throw $exception;
        }
        $diagnostics[] = get_class($exception) . ': ' . $exception->getMessage();
        $exception_recorded = true;
    }
    if (isset($scenario['expected_exception']) && !$exception_recorded) {
        throw new RuntimeException('The expected legacy rendering exception did not occur');
    }
} else {
    // The page includes ./include/auth.php and ./lib files relative to the
    // working directory. Authentication is outside these scenarios, so only
    // that file is replaced; everything else is the real tree.
    mkdir($directory . '/www/include', 0700, true);
    file_put_contents($directory . '/www/include/auth.php', '<?php');
    symlink($root . '/lib', $directory . '/www/lib');
    foreach (scandir($root . '/include') as $entry) {
        if ($entry !== '.' && $entry !== '..' && $entry !== 'auth.php') {
            symlink($root . '/include/' . $entry, $directory . '/www/include/' . $entry);
        }
    }
    chdir($directory . '/www');
    require $root . '/' . $page;
}
