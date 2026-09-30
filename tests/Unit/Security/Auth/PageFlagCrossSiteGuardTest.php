<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

/*
 * Some pages change data because of a request variable other than 'action',
 * which the central guard in include/global.php can not see: the RRD Cleaner
 * rescan, the SNMP notification receiver log purge, the Data Debug check purge,
 * the SNMP Agent notification log purge and the Kadupul log purge.
 */

function page_flag_guard_run(string $page, string $method, string $query, array $headers = array(), string $token = 'missing', array $real = array()): string
{
    $root = dirname(__DIR__, 4);
    $dir = sys_get_temp_dir() . '/page-flag-guard-' . bin2hex(random_bytes(8));
    mkdir($dir . '/include', 0700, true);
    mkdir($dir . '/lib', 0700);
    // Empty libraries the pages include by relative path, so the stubs below
    // stand in for the application and nothing reaches a database.
    $libraries = array_diff(array('functions', 'rrd', 'dsdebug', 'api_data_source', 'boost', 'clog_webapi', 'poller', 'utility'), $real);
    foreach ($libraries as $library) {
        file_put_contents($dir . '/lib/' . $library . '.php', '<?php');
    }
    foreach ($real as $library) {
        file_put_contents($dir . '/lib/' . $library . '.php', '<?php require getenv(\'PAGE_FLAG_ROOT\') . \'/lib/' . $library . '.php\';');
    }
    // A log file the log viewer may purge, so a test can see whether it did.
    file_put_contents($dir . '/cacti.log', 'ORIGINAL');

    $auth = <<<'PHP'
<?php
$root = getenv('PAGE_FLAG_ROOT');
// is_web false makes csrf_startup() switch off the load-time check, so the
// page's own guard is what decides.
$config = array('include_path' => $root . '/include', 'is_web' => false);
require $root . '/include/global_constants.php';
require $root . '/lib/html_utility.php';
require $root . '/include/csrf.php';
csrf_conf('rewrite', false);
csrf_conf('auto-session', false);
csrf_conf('secret', 'isolated-page-flag-test-secret');
function __($text, ...$args) { return $args ? vsprintf($text, $args) : $text; }
function __x($context, $text, ...$args) { return __($text, ...$args); }
function raise_message(...$args) {}
function read_config_option($name) { return $name === 'path_cactilog' ? getcwd() . '/cacti.log' : ''; }
function cacti_sizeof($value) { return is_array($value) ? count($value) : 0; }
function sanitize_search_string($value) { return $value; }
function check_changed($request, $session) {}
function get_current_page() { return 'page.php'; }
function sanitize_sql_column($column) { return $column; }
function set_page_refresh($refresh) {}
function clog_admin() { return true; }
function clog_authorized() { return true; }
function kill_session_var($name) {}
function cacti_log(...$args) {}
function get_username($id) { return 'admin'; }
function general_header() { echo 'DISPATCHED:clog'; exit; }
function top_header() { echo 'DISPATCHED:' . get_request_var('action'); exit; }
$config += array('library_path' => getcwd() . '/lib', 'rra_path' => getcwd(), 'base_path' => getcwd(), 'url_path' => '/');
session_id('page-flag-guard-test');
$_SESSION = array('sess_user_id' => 1);
$_SERVER['REQUEST_METHOD'] = getenv('PAGE_FLAG_METHOD');
$_SERVER['SERVER_NAME'] = 'kadupul.example.com';
foreach (json_decode(getenv('PAGE_FLAG_HEADERS'), true) as $name => $value) {
    $_SERVER[$name] = $value;
}
parse_str(getenv('PAGE_FLAG_QUERY'), $_REQUEST);
$_POST = $_SERVER['REQUEST_METHOD'] === 'POST' ? $_REQUEST : array();
if (getenv('PAGE_FLAG_TOKEN') === 'valid') {
    $_POST['__csrf_magic'] = csrf_get_tokens();
}
register_shutdown_function(function () { echo 'STATUS:' . (http_response_code() ?: 200); });
PHP;
    file_put_contents($dir . '/include/auth.php', $auth);

    $env = array(
        'PAGE_FLAG_ROOT' => $root,
        'PAGE_FLAG_METHOD' => $method,
        'PAGE_FLAG_QUERY' => $query,
        'PAGE_FLAG_HEADERS' => json_encode($headers),
        'PAGE_FLAG_TOKEN' => $token,
    ) + getenv();

    try {
        $process = proc_open(
            array(PHP_BINARY, '-d', 'display_errors=stderr', '-r', 'require $argv[1];', $root . '/' . $page),
            array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')),
            $pipes,
            $dir,
            $env
        );
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        if ($stderr !== '') {
            throw new RuntimeException($stderr . $stdout);
        }

        return $stdout . (file_get_contents($dir . '/cacti.log') === 'ORIGINAL' ? '' : 'LOG:CHANGED');
    } finally {
        unlink($dir . '/include/auth.php');
        foreach (array_merge($libraries, $real) as $library) {
            unlink($dir . '/lib/' . $library . '.php');
        }
        unlink($dir . '/cacti.log');
        rmdir($dir . '/include');
        rmdir($dir . '/lib');
        rmdir($dir);
    }
}

test('a cross-site GET cannot start an RRD Cleaner rescan', function (array $headers) {
    expect(page_flag_guard_run('rrdcleaner.php', 'GET', 'rescan=1&clear=1', $headers))->toBe('STATUS:405');
})->with(array(
    'Sec-Fetch-Site cross-site' => array(array('HTTP_SEC_FETCH_SITE' => 'cross-site')),
    'foreign Referer' => array(array('HTTP_REFERER' => 'https://attacker.example.net/page')),
));

test('an RRD Cleaner rescan by a method other than GET or POST is refused', function (string $method) {
    expect(page_flag_guard_run('rrdcleaner.php', $method, 'rescan=1'))->toBe('STATUS:405');
})->with(array('HEAD', 'PUT'));

test('the RRD Cleaner page still rescans from its own button', function (string $method, array $headers) {
    expect(page_flag_guard_run('rrdcleaner.php', $method, 'header=false&rescan=1&clear=1', $headers))
        ->toBe('DISPATCHED:restartSTATUS:200');
})->with(array(
    'same-origin XHR' => array('GET', array('HTTP_SEC_FETCH_SITE' => 'same-origin')),
    'script without headers' => array('GET', array()),
    'POST' => array('POST', array('HTTP_SEC_FETCH_SITE' => 'cross-site')),
));

test('a cross-site RRD Cleaner listing without a rescan still renders', function () {
    expect(page_flag_guard_run('rrdcleaner.php', 'GET', '', array('HTTP_SEC_FETCH_SITE' => 'cross-site')))
        ->toBe('DISPATCHED:STATUS:200');
});

test('the notification log purge needs a POST with a valid token', function (string $method, string $token, array $headers, string $expected) {
    expect(page_flag_guard_run('managers.php', $method, 'action=edit&tab=logs&id=3&purge=1', $headers, $token))
        ->toBe($expected);
})->with(array(
    'cross-site GET' => array('GET', 'missing', array('HTTP_SEC_FETCH_SITE' => 'cross-site'), 'STATUS:405'),
    'same-origin GET' => array('GET', 'missing', array('HTTP_SEC_FETCH_SITE' => 'same-origin'), 'STATUS:405'),
    'GET without headers' => array('GET', 'missing', array(), 'STATUS:405'),
    'POST without a token' => array('POST', 'missing', array(), 'STATUS:403'),
    'POST with a valid token' => array('POST', 'valid', array(), 'DISPATCHED:editSTATUS:200'),
));

test('viewing the notification log without a purge is unchanged', function () {
    expect(page_flag_guard_run('managers.php', 'GET', 'action=edit&tab=logs&id=3', array('HTTP_SEC_FETCH_SITE' => 'cross-site')))
        ->toBe('DISPATCHED:editSTATUS:200');
});

test('a cross-site GET cannot purge the Data Debug checks', function (array $headers) {
    expect(page_flag_guard_run('data_debug.php', 'GET', 'purge=1&debug=-1&header=false', $headers))->toBe('STATUS:405');
})->with(array(
    'Sec-Fetch-Site cross-site' => array(array('HTTP_SEC_FETCH_SITE' => 'cross-site')),
    'foreign Origin' => array(array('HTTP_ORIGIN' => 'https://attacker.example.net')),
    'foreign Referer' => array(array('HTTP_REFERER' => 'https://attacker.example.net/page')),
    'lookalike Referer host' => array(array('HTTP_REFERER' => 'https://kadupul.example.com.attacker.example.net/')),
));

test('a Data Debug purge by a method other than GET or POST is refused', function (string $method) {
    expect(page_flag_guard_run('data_debug.php', $method, 'purge=1'))->toBe('STATUS:405');
})->with(array('HEAD', 'PUT', 'DELETE'));

test('the Data Debug Purge button still purges', function (string $method, array $headers) {
    expect(page_flag_guard_run('data_debug.php', $method, 'purge=1&debug=-1&header=false', $headers))
        ->toBe('DISPATCHED:STATUS:200');
})->with(array(
    'same-origin XHR' => array('GET', array('HTTP_SEC_FETCH_SITE' => 'same-origin')),
    'script without headers' => array('GET', array()),
    'POST' => array('POST', array('HTTP_SEC_FETCH_SITE' => 'cross-site')),
));

test('a cross-site Data Debug listing without a purge still renders', function () {
    expect(page_flag_guard_run('data_debug.php', 'GET', 'debug=-1', array('HTTP_SEC_FETCH_SITE' => 'cross-site')))
        ->toBe('DISPATCHED:STATUS:200');
});

test('the SNMP Agent notification log purge needs a POST with a valid token', function (string $method, string $token, array $headers, string $expected) {
    expect(page_flag_guard_run('utilities.php', $method, 'action=view_snmpagent_events&purge=1&header=false', $headers, $token))
        ->toBe($expected);
})->with(array(
    'cross-site GET' => array('GET', 'missing', array('HTTP_SEC_FETCH_SITE' => 'cross-site'), 'STATUS:405'),
    'same-origin GET' => array('GET', 'missing', array('HTTP_SEC_FETCH_SITE' => 'same-origin'), 'STATUS:405'),
    'GET without headers' => array('GET', 'missing', array(), 'STATUS:405'),
    'DELETE' => array('DELETE', 'missing', array(), 'STATUS:405'),
    'POST without a token' => array('POST', 'missing', array(), 'STATUS:403'),
    'POST with a valid token' => array('POST', 'valid', array(), 'DISPATCHED:view_snmpagent_eventsSTATUS:200'),
));

test('viewing the SNMP Agent notification log without a purge is unchanged', function () {
    expect(page_flag_guard_run('utilities.php', 'GET', 'action=view_snmpagent_events', array('HTTP_SEC_FETCH_SITE' => 'cross-site')))
        ->toBe('DISPATCHED:view_snmpagent_eventsSTATUS:200');
});

test('the SNMP Agent notification log Purge button posts the token', function () {
    $source = file_get_contents(dirname(__DIR__, 4) . '/utilities.php');

    expect($source)->not->toContain('view_snmpagent_events&purge=1')
        ->and($source)->toMatch("/loadPageUsingPost\\('utilities\\.php', \\{\\s*action: 'view_snmpagent_events',\\s*purge: 1,\\s*header: 'false',\\s*__csrf_magic: csrfMagicToken\\s*\\}\\)/");
});

test('the log file purge needs a POST with a valid token', function (string $page, string $method, string $token, array $headers, string $expected) {
    expect(page_flag_guard_run($page, $method, 'purge_continue=1&header=false&filename=cacti.log', $headers, $token, array('clog_webapi')))
        ->toBe($expected);
})->with(array('clog.php', 'clog_user.php'))->with(array(
    'cross-site GET' => array('GET', 'missing', array('HTTP_SEC_FETCH_SITE' => 'cross-site'), 'STATUS:405'),
    'same-origin GET' => array('GET', 'missing', array('HTTP_SEC_FETCH_SITE' => 'same-origin'), 'STATUS:405'),
    'GET without headers' => array('GET', 'missing', array(), 'STATUS:405'),
    'PUT' => array('PUT', 'missing', array(), 'STATUS:405'),
    'POST without a token' => array('POST', 'missing', array(), 'STATUS:403'),
    'POST with a valid token' => array('POST', 'valid', array(), 'DISPATCHED:clogSTATUS:200LOG:CHANGED'),
));

test('viewing the log file and its purge prompt is unchanged', function (string $query) {
    expect(page_flag_guard_run('clog.php', 'GET', $query, array('HTTP_SEC_FETCH_SITE' => 'cross-site'), 'missing', array('clog_webapi')))
        ->toBe('DISPATCHED:clogSTATUS:200');
})->with(array('filename=cacti.log', 'purge=1&filename=cacti.log'));

test('the log file Continue button posts the token', function () {
    $source = file_get_contents(dirname(__DIR__, 4) . '/lib/clog_webapi.php');

    expect($source)->not->toContain('?purge_continue=1')
        ->and($source)->toMatch("/loadPageUsingPost\\(location\\.pathname, \\{\\s*purge_continue: 1,\\s*header: 'false',/");
});
