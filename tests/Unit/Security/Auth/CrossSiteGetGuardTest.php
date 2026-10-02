<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

require_once dirname(__DIR__, 3) . '/Helpers/ChildProcessCoverage.php';

/*
 * State-changing actions that pages still send as same-origin GET must not
 * run from a GET another site started, or by a method that carries no token.
 */

function cross_site_guard_run(string $method, string $query, array $headers = array()): array
{
    $root = dirname(__DIR__, 4);

    $program = <<<'PHP'
$config = array('include_path' => $argv[1] . '/include', 'is_web' => false);
$_SERVER['REQUEST_METHOD'] = $argv[2];
$_SERVER['SERVER_NAME'] = 'kadupul.example.com';
foreach (json_decode($argv[4], true) as $name => $value) {
    $_SERVER[$name] = $value;
}
parse_str($argv[3], $_REQUEST);
register_shutdown_function(function () { echo http_response_code() ?: 200; $GLOBALS['nativeChildCoverageMarkers'][] = 'response-status-readback'; });
require $argv[1] . '/lib/html_utility.php';
require $argv[1] . '/include/csrf.php';
cacti_require_post_actions(array('save', 'update_data', 'changepassword'));
if (function_exists('csrf_refuse_cross_site_actions')) {
    csrf_refuse_cross_site_actions();
}
echo 'DISPATCHED:';
PHP;

    $process = proc_open(
        child_coverage_command(array(PHP_BINARY, '-d', 'display_errors=stderr', '-r', $program, $root, $method, $query, json_encode($headers)), $coverage_dir, child_coverage_registration(__FILE__, 'cross-site-dispatch', array($method, $query, $headers), array('response-status-readback'), array('include/csrf.php', 'lib/html_utility.php'), array())),
        array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')),
        $pipes
    );
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);
    child_coverage_collect($coverage_dir);

    return array('stdout' => $stdout, 'stderr' => $stderr);
}

function cross_site_bootstrap_run(array $headers, string $query): array
{
    $root = dirname(__DIR__, 4);
    $directory = sys_get_temp_dir() . '/cross-site-bootstrap-' . bin2hex(random_bytes(8));
    mkdir($directory . '/include/vendor', 0700, true);
    mkdir($directory . '/lib', 0700);
    mkdir($directory . '/log', 0700);
    try {
        foreach (array('global.php', 'runtime.php', 'cacti_version') as $file) {
            copy($root . '/include/' . $file, $directory . '/include/' . $file);
        }
        file_put_contents($directory . '/include/config.php', '<?php $url_path = "/";');
        foreach (array('functions', 'headers_secure', 'html', 'html_utility', 'html_validate') as $module) {
            file_put_contents($directory . '/lib/' . $module . '.php', '<?php require ' . var_export($root . '/lib/' . $module . '.php', true) . ';');
        }
        file_put_contents($directory . '/lib/database.php', '<?php require ' . var_export($root . '/tests/Fixtures/force-https-native-database.php', true) . ';');
        foreach (array('auth', 'html_form', 'html_filter', 'variables', 'mib_cache', 'poller', 'snmpagent', 'aggregate', 'api_automation') as $module) {
            file_put_contents($directory . '/lib/' . $module . '.php', '<?php');
        }
        file_put_contents($directory . '/lib/plugins.php', '<?php function api_plugin_hook($name) {}');
        foreach (array('global_languages', 'plugins', 'global_arrays', 'global_settings', 'global_form') as $module) {
            file_put_contents($directory . '/include/' . $module . '.php', '<?php');
        }
        file_put_contents($directory . '/include/global_constants.php', '<?php require ' . var_export($root . '/include/global_constants.php', true) . ';');
        file_put_contents($directory . '/include/vendor/autoload.php', '<?php');
        // The actual CSRF implementation and its dependencies are retained.
        symlink($root . '/include/vendor/csrf', $directory . '/include/vendor/csrf');
        file_put_contents($directory . '/include/csrf.php', '<?php require ' . var_export($root . '/include/csrf.php', true) . ';');
        $program = <<<'PHP'
$scenario = array('force' => '');
define('IN_CACTI_INSTALL', true);
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['SERVER_NAME'] = 'kadupul.example.com';
$_SERVER['HTTP_HOST'] = 'kadupul.example.com';
$_SERVER['SCRIPT_NAME'] = '/host.php';
$_SERVER['REQUEST_URI'] = '/host.php?' . $argv[2];
$_SERVER['REMOTE_ADDR'] = '192.0.2.20';
$_SERVER += json_decode($argv[3], true);
parse_str($argv[2], $_GET);
$_REQUEST = $_GET;
register_shutdown_function(function () { echo 'STATUS:' . (http_response_code() ?: 200); $GLOBALS['nativeChildCoverageMarkers'][] = 'bootstrap-status-readback'; });
require $argv[1] . '/include/global.php';
echo 'DISPATCHED:';
PHP;
        $worker = proc_open(child_coverage_command(array(PHP_BINARY, '-d', 'display_errors=stderr', '-d', 'session.save_path=' . $directory, '-r', $program, $directory, $query, json_encode($headers)), $coverage_dir, child_coverage_registration(__FILE__, 'cross-site-bootstrap', array($headers, $query, hash_file('sha256', $directory . '/include/global.php')), array('bootstrap-status-readback'), array('include/csrf.php'), array('include/global.php', 'include/runtime.php', 'include/cacti_version', 'include/global_constants.php', 'tests/Fixtures/force-https-native-database.php'))), array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($worker);
        child_coverage_collect($coverage_dir);
        return array('stdout' => $output, 'stderr' => $error);
    } finally {
        unlink($directory . '/include/vendor/csrf');
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($directory);
    }
}

test('actual global bootstrap guards mutations before dispatch while permitting listings and same-origin actions', function (array $headers, string $query, string $expected) {
    $result = cross_site_bootstrap_run($headers, $query);
    expect($result['stderr'])->toBe('')->and($result['stdout'])->toBe($expected);
})->with(array(
    'cross-site mutation' => array(array('HTTP_SEC_FETCH_SITE' => 'cross-site'), 'action=lock&id=1', 'STATUS:405'),
    'foreign origin mutation' => array(array('HTTP_ORIGIN' => 'https://other.example'), 'action=lock&id=1', 'STATUS:405'),
    'cross-site listing' => array(array('HTTP_SEC_FETCH_SITE' => 'cross-site'), '', 'DISPATCHED:STATUS:200'),
    'same-origin mutation' => array(array('HTTP_SEC_FETCH_SITE' => 'same-origin'), 'action=lock&id=1', 'DISPATCHED:STATUS:200'),
));

test('a cross-site GET cannot run a state-changing action', function (string $query, array $headers) {
    $result = cross_site_guard_run('GET', $query, $headers);

    expect($result['stderr'])->toBe('')
        ->and($result['stdout'])->toBe('405');
})->with(array(
    'Sec-Fetch-Site cross-site' => array('action=delete_node&id=4', array('HTTP_SEC_FETCH_SITE' => 'cross-site')),
    'Sec-Fetch-Site same-site' => array('action=gt_remove&id=4', array('HTTP_SEC_FETCH_SITE' => 'same-site')),
    'foreign Origin' => array('action=lock&id=1', array('HTTP_ORIGIN' => 'https://attacker.example.net')),
    'foreign Referer' => array('action=query_reload&id=1', array('HTTP_REFERER' => 'https://attacker.example.net/page')),
    'confirmed bulk action' => array('action=actions&selected_items=a%3A0%3A%7B%7D', array('HTTP_SEC_FETCH_SITE' => 'cross-site')),
    'table purge' => array('action=purge', array('HTTP_SEC_FETCH_SITE' => 'cross-site')),
    'table purge, foreign Origin' => array('action=purge', array('HTTP_ORIGIN' => 'https://attacker.example.net')),
    'rule quick edit' => array('action=qedit&id=1&name=x', array('HTTP_SEC_FETCH_SITE' => 'cross-site')),
));

test('a state-changing action by a method other than GET or POST is refused', function (string $method) {
    expect(cross_site_guard_run($method, 'action=remove&id=4')['stdout'])->toBe('405');
})->with(array('HEAD', 'PUT', 'DELETE'));

test('same-origin and header-less GET requests still reach the page', function (string $query, array $headers) {
    $result = cross_site_guard_run('GET', $query, $headers);

    expect($result['stderr'])->toBe('')
        ->and($result['stdout'])->toBe('DISPATCHED:200');
})->with(array(
    'same-origin XHR' => array('action=create_node&id=4', array('HTTP_SEC_FETCH_SITE' => 'same-origin')),
    'typed or bookmarked' => array('action=lock&id=1', array('HTTP_SEC_FETCH_SITE' => 'none')),
    'script without headers' => array('action=query_reload&id=1', array()),
    'own Referer with port' => array('action=unlock&id=1', array('HTTP_REFERER' => 'https://kadupul.example.com:8443/tree.php')),
    'own Origin' => array('action=item_remove&id=1', array('HTTP_ORIGIN' => 'https://KADUPUL.example.com')),
    'same-origin table purge' => array('action=purge', array('HTTP_SEC_FETCH_SITE' => 'same-origin')),
    'own Referer rule quick edit' => array('action=qedit&id=1', array('HTTP_REFERER' => 'https://kadupul.example.com/automation_graph_rules.php')),
    'read-only action cross-site' => array('action=edit&id=1', array('HTTP_SEC_FETCH_SITE' => 'cross-site')),
    'bulk confirmation page cross-site' => array('action=actions', array('HTTP_SEC_FETCH_SITE' => 'cross-site')),
));

test('host comparison ignores ports and keeps bare IPv6 addresses whole', function (string $server, string $host, bool $expected) {
    $root = dirname(__DIR__, 4);
    $program = '$config = array("include_path" => $argv[1] . "/include", "is_web" => false);'
        . 'require $argv[1] . "/lib/html_utility.php"; require $argv[1] . "/include/csrf.php";'
        . 'echo csrf_strip_host_port($argv[2]) === $argv[3] ? "same" : "different"; $GLOBALS["nativeChildCoverageMarkers"][] = "host-comparison-readback";';
    $process = proc_open(
        child_coverage_command(array(PHP_BINARY, '-r', $program, $root, $server, $host), $coverage_dir, child_coverage_registration(__FILE__, 'host-comparison', array($server, $host), array('host-comparison-readback'), array('include/csrf.php'), array())),
        array(1 => array('pipe', 'w')),
        $pipes
    );
    $stdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    proc_close($process);
    child_coverage_collect($coverage_dir);

    expect($stdout)->toBe($expected ? 'same' : 'different');
})->with(array(
    'name with port' => array('kadupul.example.com:8443', 'kadupul.example.com', true),
    'bracketed IPv6 with port' => array('[2001:db8::1]:8443', '[2001:db8::1]', true),
    'bare IPv6' => array('2001:db8::1', '2001:db8::1', true),
    'bare IPv6 is not shortened' => array('2001:db8::1', '2001:db8:', false),
));
