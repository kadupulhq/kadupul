<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

require_once dirname(__DIR__, 3) . '/Helpers/NativeChildCoverageEvidence.php';

/** @return list<string> */
function admin_mutation_coverage_sources(): array
{
    return array('composer.lock', 'tests/composer.lock', 'tests/Fixtures/rrd-process-coverage.php', 'tests/Helpers/NativeChildCoverageEvidence.php', 'tests/Unit/Security/Auth/AdminMutationCsrfTest.php', 'include/global_constants.php', 'include/vendor/csrf/csrf-magic.php', 'src/IdentityAccess/Infrastructure/Legacy/PermissionMutation.php', 'src/IdentityAccess/Infrastructure/Legacy/PermissionAssociations.php', 'plugins.php', 'user_admin.php', 'user_group_admin.php', 'lib/html_utility.php', 'lib/auth.php', 'lib/rrd.php', 'src/Graphing/Infrastructure/Rrd/ProxyCipher.php', 'lib/dsdebug.php', 'lib/rrd_maintenance.php', 'lib/poller.php', 'lib/boost.php', 'lib/api_data_source.php', 'lib/rrdcheck.php', 'lib/dsstats.php');
}

function admin_mutation_coverage_program(): string
{
    // The child registry is independent of the merger registry.
    return <<<'PHP'
require_once $root . '/tests/Helpers/NativeChildCoverageEvidence.php';
$GLOBALS['nativeChildCoverageSnapshot'] = NativeChildCoverageEvidence::snapshot(
    $root,
    'tests/Unit/Security/Auth/AdminMutationCsrfTest.php',
    json_encode(array($adminCsrfScenario, hash_file('sha256', __FILE__)), JSON_THROW_ON_ERROR),
    array('composer.lock', 'tests/composer.lock', 'tests/Fixtures/rrd-process-coverage.php', 'tests/Helpers/NativeChildCoverageEvidence.php', 'tests/Unit/Security/Auth/AdminMutationCsrfTest.php', 'include/global_constants.php', 'include/vendor/csrf/csrf-magic.php', 'src/IdentityAccess/Infrastructure/Legacy/PermissionMutation.php', 'src/IdentityAccess/Infrastructure/Legacy/PermissionAssociations.php', 'plugins.php', 'user_admin.php', 'user_group_admin.php', 'lib/html_utility.php', 'lib/auth.php', 'lib/rrd.php', 'src/Graphing/Infrastructure/Rrd/ProxyCipher.php', 'lib/dsdebug.php', 'lib/rrd_maintenance.php', 'lib/poller.php', 'lib/boost.php', 'lib/api_data_source.php', 'lib/rrdcheck.php', 'lib/dsstats.php')
);
$GLOBALS['nativeChildCoverageMarkers'] = array();
define('ADMIN_MUTATION_TEST_COVERAGE', true);
define('RRD_TEST_COVERAGE_DIRECTORY', __DIR__);
require $root . '/tests/Fixtures/rrd-process-coverage.php';
PHP;
}

/** @param list<string> $markers */
function admin_mutation_import_coverage(\PHPUnit\Framework\TestCase $test, \SebastianBergmann\CodeCoverage\CodeCoverage $coverage, string $directory, string $scenario, array $markers, string $controller, bool $verify): void
{
    $reports = glob($directory . '/*.coverage');
    $test->assertCount(1, $reports);
    $sources = admin_mutation_coverage_sources();
    $hits = array($controller, 'lib/html_utility.php');
    $child = NativeChildCoverageEvidence::load($reports[0], dirname(__DIR__, 4), 'tests/Unit/Security/Auth/AdminMutationCsrfTest.php', $scenario, $sources, $markers, $hits);
    if ($verify) {
        $test->assertSame(count($sources) + count($markers) + 10, NativeChildCoverageEvidence::verifyRejections($reports[0], dirname(__DIR__, 4), 'tests/Unit/Security/Auth/AdminMutationCsrfTest.php', $scenario, $sources, $markers, $hits, 'lib/rrd.php'));
        $receipt = file_get_contents($reports[0] . '.json');
        $changed = json_decode($receipt, true, 512, JSON_THROW_ON_ERROR);
        $changed['sources'][$controller] = str_repeat('0', 64);
        try {
            file_put_contents($reports[0] . '.json', json_encode($changed, JSON_THROW_ON_ERROR));
            expect(fn() => NativeChildCoverageEvidence::load($reports[0], dirname(__DIR__, 4), 'tests/Unit/Security/Auth/AdminMutationCsrfTest.php', $scenario, $sources, $markers, $hits))
                ->toThrow(RuntimeException::class, 'Native coverage source is missing or stale: ' . $controller);
        } finally {
            file_put_contents($reports[0] . '.json', $receipt);
        }
    }
    $coverage->merge($child);
    unlink($reports[0]);
    unlink($reports[0] . '.json');
}

test('plugin lifecycle redirects preserve AJAX query parameters', function () {
    $root = dirname(__DIR__, 4);
    $dir = sys_get_temp_dir() . '/plugin-redirect-' . bin2hex(random_bytes(8));
    mkdir($dir . '/include', 0700, true);
    file_put_contents($dir . '/include/auth.php', '<?php');
    $coverage = $this->getTestResultObject()->getCodeCoverage();
    $program = '<?php $root = ' . var_export($root, true) . ';';
    if ($coverage !== null) {
        $program .= '$adminCsrfScenario = json_encode(array("plugin-redirect", $_POST["mode"], $_POST["header"] ?? "", (int) $_POST["test_state"]), JSON_THROW_ON_ERROR);'
            . admin_mutation_coverage_program();
    }
    $program .= <<<'PHP'
function csrf_startup() {
    csrf_conf('rewrite', false);
    csrf_conf('defer', true);
    csrf_conf('auto-session', false);
    csrf_conf('secret', 'isolated-plugin-redirect-secret');
}
require $root . '/include/vendor/csrf/csrf-magic.php';
require $root . '/lib/html_utility.php';
require $root . '/include/global_constants.php';
function __($value) { return $value; }
function read_config_option($name) { return ''; }
function cacti_sizeof($value) { return is_array($value) ? count($value) : 0; }
function db_execute_prepared(...$args) { $GLOBALS['adminCsrfMutationObserved'] = true; header('X-Test-Mutation: remote'); }
function db_fetch_assoc($sql) { return array(array('directory' => 'fixture')); }
function sanitize_search_string($value) { return $value; }
function api_plugin_install($id) { $GLOBALS['adminCsrfMutationObserved'] = true; header('X-Test-Mutation: install'); }
function api_plugin_uninstall($id) { $GLOBALS['adminCsrfMutationObserved'] = true; header('X-Test-Mutation: uninstall'); }
function api_plugin_enable($id) { $GLOBALS['adminCsrfMutationObserved'] = true; header('X-Test-Mutation: enable'); }
function api_plugin_disable($id) { $GLOBALS['adminCsrfMutationObserved'] = true; header('X-Test-Mutation: disable'); }
function api_plugin_moveup($id) { $GLOBALS['adminCsrfMutationObserved'] = true; header('X-Test-Mutation: moveup'); }
function api_plugin_movedown($id) { $GLOBALS['adminCsrfMutationObserved'] = true; header('X-Test-Mutation: movedown'); }
$config = array('poller_id' => 2, 'url_path' => '/');
$plugins_integrated = array();
session_id('plugin-redirect-test');
$_SESSION = array('sess_user_id' => 42, 'sess_plugins_state' => (int) $_POST['test_state']);
// This fixture tests redirects; the separate request matrix tests token rejection.
$_POST['__csrf_magic'] = csrf_get_tokens();
register_shutdown_function(function () {
    if (http_response_code() === 302 && preg_grep('/^Location: .+/', headers_list())) {
        $GLOBALS['nativeChildCoverageMarkers'][] = 'redirect-response-observed';
    }
    if (!empty($GLOBALS['adminCsrfMutationObserved'])) {
        $GLOBALS['nativeChildCoverageMarkers'][] = 'fixture-mutation-boundary-observed';
    }
});
require $root . '/plugins.php';
PHP;
    if (file_put_contents($dir . '/router.php', $program) !== strlen($program)) {
        throw new RuntimeException('Unable to preserve plugin redirect producer');
    }
    $socket = stream_socket_server('tcp://127.0.0.1:0', $error, $message);
    if ($socket === false) {
        throw new RuntimeException($message);
    }
    $address = stream_socket_get_name($socket, false);
    fclose($socket);
    $server = null;
    try {
        // Coverage hooks and JIT are incompatible in the HTTP-server SAPI.
        $server = proc_open(
            array(PHP_BINARY, '-d', 'error_reporting=' . error_reporting(), '-d', 'opcache.jit=off', '-d', 'opcache.jit_buffer_size=0', '-d', 'pcov.directory=' . $root,
                '-d', 'pcov.exclude=~/(include/vendor|tests)/~', '-S', $address, 'router.php'),
            array(0 => array('pipe', 'r'), 1 => array('file', $dir . '/server.log', 'a'),
                2 => array('file', $dir . '/server.log', 'a')),
            $pipes,
            $dir
        );
        if (!is_resource($server)) {
            throw new RuntimeException('Unable to start plugin redirect server');
        }
        fclose($pipes[0]);
        $ready = false;
        for ($attempt = 0; $attempt < 100; $attempt++) {
            $probe = @stream_socket_client('tcp://' . $address, $error, $message, 0.1);
            if ($probe !== false) {
                fclose($probe);
                $ready = true;
                break;
            }
            usleep(20000);
        }
        expect($ready)->toBeTrue();
        foreach (array('install', 'uninstall', 'enable', 'disable', 'moveup', 'movedown', 'remote_enable', 'remote_disable') as $mode) {
            foreach (array(false, true) as $ajax) {
                foreach ($mode === 'install' ? array(-1, 0) : array(-1) as $state) {
                    $data = array('mode' => $mode, 'id' => 'fixture', 'test_state' => $state);
                    if ($ajax) {
                        $data['header'] = 'false';
                    }
                    $context = stream_context_create(array('http' => array('method' => 'POST',
                        'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
                        'content' => http_build_query($data), 'follow_location' => 0, 'timeout' => 5)));
                    file_get_contents('http://' . $address . '/plugins.php', false, $context);
                    expect($http_response_header[0])->toContain('302');
                    $location = 'plugins.php' . ($state >= 0 ? '?state=5' : '');
                    if ($ajax) {
                        $location .= ($state >= 0 ? '&' : '?') . 'header=false';
                    }
                    expect($http_response_header)->toContain('Location: ' . $location);
                    expect($http_response_header)->toContain('X-Test-Mutation: ' . (str_starts_with($mode, 'remote_') ? 'remote' : $mode));
                    if ($coverage !== null) {
                        $scenario = json_encode(array(json_encode(array('plugin-redirect', $mode, $ajax ? 'false' : '', $state), JSON_THROW_ON_ERROR), hash('sha256', $program)), JSON_THROW_ON_ERROR);
                        admin_mutation_import_coverage($this, $coverage, $dir, $scenario, array('redirect-response-observed', 'fixture-mutation-boundary-observed'), 'plugins.php', $mode === 'install' && !$ajax && $state === -1);
                    }
                }
            }
        }
        $serverLog = file_get_contents($dir . '/server.log');
        if (preg_match('/PHP (Fatal error|Warning|Notice)/', $serverLog)) {
            throw new RuntimeException($serverLog);
        }
    } finally {
        if (is_resource($server)) {
            proc_terminate($server);
            proc_close($server);
        }
        unlink($dir . '/include/auth.php');
        rmdir($dir . '/include');
        foreach (glob($dir . '/*') as $file) {
            unlink($file);
        }
        rmdir($dir);
    }
});

test('account and plugin administration reject unprotected mutation requests', function ($controller, $route, $method, $token, $expected) {
    $root = dirname(__DIR__, 4);
    $dir = sys_get_temp_dir() . '/admin-csrf-' . bin2hex(random_bytes(8));
    mkdir($dir . '/include', 0700, true);
    file_put_contents($dir . '/include/auth.php', '<?php');
    $program = <<<'PHP'
function csrf_startup() {
    csrf_conf('rewrite', false);
    csrf_conf('defer', true);
    csrf_conf('auto-session', false);
    csrf_conf('secret', 'isolated-admin-test-secret');
}
require $argv[1] . '/include/vendor/csrf/csrf-magic.php';
require $argv[1] . '/lib/html_utility.php';
require $argv[1] . '/include/global_constants.php';
function __($value) { return $value; }
function read_config_option($name) { return ''; }
function cacti_sizeof($value) { return is_array($value) ? count($value) : 0; }
// The real permission adapter now opens a transaction on the active PDO.
// Use actual isolated SQL state so admitted requests reach and complete it.
$db = new PDO('sqlite::memory:', null, null, array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
// Deterministic MySQL RAND compatibility for the legacy permission-reset SQL.
$db->sqliteCreateFunction('RAND', static fn () => 0, 0);
$db->sqliteCreateFunction('FLOOR', static fn ($value) => floor($value), 1);
$db->exec('CREATE TABLE user_auth(id INTEGER PRIMARY KEY, reset_perms INTEGER NOT NULL, policy_graphs INTEGER);
CREATE TABLE user_auth_group(id INTEGER PRIMARY KEY, policy_graphs INTEGER);
CREATE TABLE user_auth_group_members(group_id INTEGER, user_id INTEGER);
CREATE TABLE user_auth_perms(user_id INTEGER, item_id INTEGER, type INTEGER);
CREATE TABLE user_auth_group_perms(group_id INTEGER, item_id INTEGER, type INTEGER);
INSERT INTO user_auth VALUES(2,0,0),(42,0,0);
INSERT INTO user_auth_group VALUES(2,0);
INSERT INTO user_auth_group_members VALUES(2,2);
INSERT INTO user_auth_perms VALUES(2,2,1);
INSERT INTO user_auth_group_perms VALUES(2,2,1);');
$database_hostname = 'isolated';
$database_port = '0';
$database_default = 'admin-csrf-fixture';
$database_sessions = array("$database_hostname:$database_port:$database_default" => $db);
$affected = 0;
$mutationWrites = 0;
function db_execute_prepared($sql, $parameters = array(), ...$options) {
    global $db, $affected, $mutationWrites;
    if ($GLOBALS['argv'][2] === 'plugins.php') { $GLOBALS['adminCsrfMutationObserved'] = true; echo 'WRITE'; exit; }
    $statement = $db->prepare($sql);
    $result = $statement->execute($parameters);
    $affected = $statement->rowCount();
    if ($result) $mutationWrites++;
    return $result;
}
function db_affected_rows($connection = null) { return $GLOBALS['affected']; }
function db_fetch_assoc_prepared($sql, $parameters = array()) {
    $statement = $GLOBALS['db']->prepare($sql);
    $statement->execute($parameters);
    return $statement->fetchAll(PDO::FETCH_ASSOC);
}
function db_execute($sql) { return db_execute_prepared($sql); }
function array_rekey($rows, $key, $value) { return array_column($rows, $value, $key); }
function sanitize_unserialize_selected_items($value) { $GLOBALS['adminCsrfMutationObserved'] = true; echo 'WRITE'; exit; }
function db_begin_transaction($connection = null) { return ($connection ?? $GLOBALS['db'])->beginTransaction(); }
function db_commit_transaction($connection = null) { return ($connection ?? $GLOBALS['db'])->commit(); }
function db_rollback_transaction($connection = null) { return ($connection ?? $GLOBALS['db'])->rollBack(); }
require $argv[1] . '/lib/auth.php';
function db_fetch_assoc($sql) { return array(array('directory' => '2')); }
function db_fetch_cell_prepared(...$args) { return 1; }
function sanitize_search_string($value) { return $value; }
function api_plugin_install($id) { $GLOBALS['adminCsrfMutationObserved'] = true; echo 'WRITE'; exit; }
function api_plugin_uninstall($id) { $GLOBALS['adminCsrfMutationObserved'] = true; echo 'WRITE'; exit; }
function api_plugin_enable($id) { $GLOBALS['adminCsrfMutationObserved'] = true; echo 'WRITE'; exit; }
function api_plugin_disable($id) { $GLOBALS['adminCsrfMutationObserved'] = true; echo 'WRITE'; exit; }
function api_plugin_moveup($id) { $GLOBALS['adminCsrfMutationObserved'] = true; echo 'WRITE'; exit; }
function api_plugin_movedown($id) { $GLOBALS['adminCsrfMutationObserved'] = true; echo 'WRITE'; exit; }
$config = array('poller_id' => 2, 'url_path' => '/');
$plugins_integrated = array();
session_id('admin-csrf-test');
$_SESSION = array('sess_user_id' => 42);
$_SERVER['REQUEST_METHOD'] = $argv[4];
$_REQUEST = array('id' => '2', 'user_id' => '2', 'group_id' => '2', 'type' => 'graph', 'policy_graphs' => '1', 'selected_items' => 'fixture', 'drp_action' => '1');
if ($argv[3] === 'update_policy') {
    $_REQUEST['update_policy'] = '1';
} else {
    $_REQUEST['action'] = $argv[3];
}
if ($argv[5] === 'action_array') $_REQUEST['action'] = array('save');
if ($argv[2] === 'plugins.php') {
    $_REQUEST['mode'] = $argv[5] === 'action_array' ? array($argv[3]) : $argv[3];
}
$_POST = $argv[4] === 'POST' ? $_REQUEST : array();
$_GET = $argv[4] === 'GET' ? $_REQUEST : array();
if ($argv[5] === 'valid') $_POST['__csrf_magic'] = csrf_get_tokens();
if ($argv[5] === 'query') $_GET['__csrf_magic'] = $_REQUEST['__csrf_magic'] = csrf_get_tokens();
if ($argv[5] === 'array') $_POST['__csrf_magic'] = array('bad');
if ($argv[5] === 'forged') $_POST['__csrf_magic'] = 'sid:forged,1';
register_shutdown_function(function () use ($db) {
    if ($GLOBALS['mutationWrites'] > 0) echo 'WRITE';
    $epochRoute = $GLOBALS['argv'][2] !== 'plugins.php'
        && in_array($GLOBALS['argv'][3], array('update_policy', 'perm_remove'), true);
    if ($epochRoute) {
        echo 'EPOCH:' . $db->query('SELECT reset_perms FROM user_auth WHERE id = 2')->fetchColumn();
        echo 'FOREIGN:' . $db->query('SELECT reset_perms FROM user_auth WHERE id = 42')->fetchColumn();
    }
    $status = http_response_code() ?: 200;
    echo 'STATUS:' . $status;
    if (in_array($status, array(200, 302, 400, 403, 405), true)) {
        $GLOBALS['nativeChildCoverageMarkers'][] = 'request-status-observed';
    }
    if (in_array($status, array(200, 302), true) && (!empty($GLOBALS['adminCsrfMutationObserved']) || $GLOBALS['mutationWrites'] > 0)) {
        $GLOBALS['nativeChildCoverageMarkers'][] = 'fixture-mutation-boundary-observed';
    } elseif (in_array($status, array(400, 403, 405), true) && empty($GLOBALS['adminCsrfMutationObserved']) && $GLOBALS['mutationWrites'] === 0) {
        $GLOBALS['nativeChildCoverageMarkers'][] = 'request-denied-before-mutation';
    }
    if ($epochRoute && (int) $db->query('SELECT reset_perms FROM user_auth WHERE id = 2')->fetchColumn() === (in_array($status, array(200, 302), true) ? 1 : 0) && (int) $db->query('SELECT reset_perms FROM user_auth WHERE id = 42')->fetchColumn() === 0) {
        $GLOBALS['nativeChildCoverageMarkers'][] = 'scoped-epochs-observed';
    }
});
require $argv[1] . '/' . $argv[2];
PHP;
    $coverage = $this->getTestResultObject()->getCodeCoverage();
    if ($coverage !== null) {
        $program = '$root = $argv[1]; $adminCsrfScenario = json_encode(array("admin-request", $argv[2], $argv[3], $argv[4], $argv[5]), JSON_THROW_ON_ERROR);'
            . admin_mutation_coverage_program() . $program;
    }
    $program = '<?php ' . $program;
    if (file_put_contents($dir . '/scenario.php', $program) !== strlen($program)) {
        throw new RuntimeException('Unable to preserve administrative request producer');
    }
    try {
        $process = proc_open(array(PHP_BINARY, '-d', 'error_reporting=' . error_reporting(), '-d', 'pcov.directory=' . $root, '-d', 'pcov.exclude=~/(include/vendor|tests)/~', $dir . '/scenario.php', $root, $controller, $route, $method, $token), array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes, $dir);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        if (proc_close($process) !== 0) {
            throw new RuntimeException($stderr . $stdout);
        }
        expect($stderr)->toBe('');
        $epochRoute = $controller !== 'plugins.php' && in_array($route, array('update_policy', 'perm_remove'), true);
        $epochs = $epochRoute ? 'EPOCH:' . ($expected === 200 ? '1' : '0') . 'FOREIGN:0' : '';
        $status = $epochRoute && $expected === 200 ? 302 : $expected;
        expect($stdout)->toBe(($expected === 200 ? 'WRITE' : '') . $epochs . 'STATUS:' . $status);
        if ($coverage !== null) {
            $scenario = json_encode(array(json_encode(array('admin-request', $controller, $route, $method, $token), JSON_THROW_ON_ERROR), hash('sha256', $program)), JSON_THROW_ON_ERROR);
            $markers = array('request-status-observed', $expected === 200 ? 'fixture-mutation-boundary-observed' : 'request-denied-before-mutation');
            if ($epochRoute) {
                $markers[] = 'scoped-epochs-observed';
            }
            admin_mutation_import_coverage($this, $coverage, $dir, $scenario, $markers, $controller, $controller === 'user_admin.php' && $route === 'update_policy' && (($method === 'GET' && $token === 'missing') || ($method === 'POST' && $token === 'valid')));
        }
    } finally {
        unlink($dir . '/include/auth.php');
        rmdir($dir . '/include');
        foreach (glob($dir . '/*.coverage*') as $file) {
            unlink($file);
        }
        unlink($dir . '/scenario.php');
        rmdir($dir);
    }
})->with(array(
    array('user_admin.php', 'update_policy'),
    array('user_admin.php', 'perm_remove'),
    array('user_admin.php', 'actions'),
    array('user_group_admin.php', 'update_policy'),
    array('user_group_admin.php', 'perm_remove'),
    array('user_group_admin.php', 'actions'),
    array('plugins.php', 'install'),
    array('plugins.php', 'uninstall'),
    array('plugins.php', 'enable'),
    array('plugins.php', 'disable'),
    array('plugins.php', 'moveup'),
    array('plugins.php', 'movedown'),
    array('plugins.php', 'remote_enable'),
    array('plugins.php', 'remote_disable'),
))
    ->with(array(
        array('GET', 'missing', 405),
        array('GET', 'valid', 405),
        array('HEAD', 'missing', 405),
        array('PUT', 'missing', 405),
        array('', 'missing', 405),
        array('GET', 'action_array', 400),
        array('POST', 'action_array', 400),
        array('POST', 'missing', 403),
        array('POST', 'query', 403),
        array('POST', 'array', 403),
        array('POST', 'forged', 403),
        array('POST', 'valid', 200),
    ));
