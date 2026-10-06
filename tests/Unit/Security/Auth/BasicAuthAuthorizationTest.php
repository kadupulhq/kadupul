<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

test('Basic authentication cannot bypass first-request authorization or account eligibility', function ($scenario, $mode, $events, $user, $status) {
    $root = dirname(__DIR__, 4);
    $dir = sys_get_temp_dir() . '/basic-auth-' . bin2hex(random_bytes(8));
    mkdir($dir, 0700);
    // Isolate application initialization, not authentication or authorization.
    file_put_contents($dir . '/global.php', '<?php');
    mkdir($dir . '/include', 0700);
    file_put_contents($dir . '/include/auth.php', '<?php');
    $coverage = $this->getTestResultObject()->getCodeCoverage();
    $prelude = '';
    if ($coverage !== null) {
        $prelude = 'define("BASIC_AUTH_TEST_COVERAGE",true);'
            . 'define("RRD_TEST_COVERAGE_DIRECTORY",' . var_export($dir, true) . ');'
            . 'require ' . var_export($root . '/tests/Fixtures/rrd-process-coverage.php', true) . ';';
    }
    try {
        $process = proc_open(
            array(PHP_BINARY, '-d', 'include_path=' . $dir, '-d', 'pcov.directory=' . $root, '-d', 'pcov.exclude=~/(include/vendor|tests)/~', '-r', $prelude . 'require ' . var_export($root . '/tests/Fixtures/basic-auth-authorization.php', true) . ';', $root, $scenario, $mode),
            array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')),
            $pipes,
            $dir
        );
        expect(is_resource($process))->toBeTrue();
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);
        if ($exit !== 0) {
            throw new RuntimeException($stderr . $stdout);
        }
        expect($stderr)->toBe('');
        expect(json_decode($stdout, true, 512, JSON_THROW_ON_ERROR))->toBe(array('events' => $events, 'user' => $user, 'status' => $status));
        if ($coverage !== null) {
            foreach (glob($dir . '/*.coverage') as $file) {
                $coverage->merge(unserialize(file_get_contents($file)));
            }
        }
    } finally {
        unlink($dir . '/include/auth.php');
        rmdir($dir . '/include');
        foreach (glob($dir . '/*') as $file) {
            unlink($file);
        }
        rmdir($dir);
    }
})->with(array(
    'first request without realm' => array('denied', 'middleware', array('LOGIN_LOG', 'REALM_CHECK', 'DENIED'), 42, 200),
    'first request with direct realm' => array('allowed', 'middleware', array('LOGIN_LOG', 'REALM_CHECK', 'HANDLER'), 42, 200),
    'first request with enabled group realm' => array('group', 'middleware', array('LOGIN_LOG', 'REALM_CHECK', 'HANDLER'), 42, 200),
    'disabled group cannot grant realm' => array('disabled_group', 'middleware', array('LOGIN_LOG', 'REALM_CHECK', 'DENIED'), 42, 200),
    'existing session still checks realm' => array('existing_denied', 'middleware', array('REALM_CHECK', 'DENIED'), 42, 200),
    'disabled account stops before handler' => array('disabled', 'middleware', array(), null, 403),
    'locked account stops before handler' => array('locked', 'middleware', array(), null, 403),
    'locked existing session stops before handler' => array('existing_locked', 'middleware', array('CLEAR_COOKIES', 'DESTROY_SESSION'), null, 403),
    'disabled user can reach logout handler' => array('existing_disabled', 'logout', array('HANDLER'), 42, 200),
    'locked user can reach logout handler' => array('existing_locked', 'logout', array('HANDLER'), 42, 200),
    'deleted user can reach logout handler' => array('existing_missing', 'logout', array('HANDLER'), 42, 200),
    'new disabled guest remains usable' => array('guest', 'middleware', array('HANDLER'), 42, 200),
    'locked new guest rejected' => array('guest_locked', 'middleware', array(), null, 403),
    'missing new guest rejected' => array('guest_missing', 'middleware', array(), null, 403),
    'locked existing guest rejected' => array('existing_guest_locked', 'middleware', array('CLEAR_COOKIES', 'DESTROY_SESSION'), null, 403),
    'enabled transition' => array('allowed', 'transition', array('ACCEPT'), null, 200),
    'disabled transition' => array('disabled', 'transition', array('REJECT'), null, 200),
    'locked transition' => array('locked', 'transition', array('REJECT'), null, 200),
    'missing account transition' => array('missing', 'transition', array('REJECT'), null, 200),
    'configured disabled guest transition' => array('guest', 'transition', array('ACCEPT'), null, 200),
    'locked guest transition rejected' => array('guest_locked', 'transition', array('REJECT'), null, 200),
    'existing configured guest session' => array('existing_guest', 'middleware', array('HANDLER'), 42, 200),
    'missing configured guest rejected' => array('existing_guest_missing', 'middleware', array('CLEAR_COOKIES', 'DESTROY_SESSION'), null, 403),
    'disabled existing session' => array('existing_disabled', 'middleware', array('CLEAR_COOKIES', 'DESTROY_SESSION'), null, 403),
    'missing existing session' => array('existing_missing', 'middleware', array('CLEAR_COOKIES', 'DESTROY_SESSION'), null, 403),
    'server PHP auth identity' => array('PHP_AUTH_USER', 'identity', array('IDENTITY'), null, 200),
    'server remote identity' => array('REMOTE_USER', 'identity', array('IDENTITY'), null, 200),
    'server redirect identity' => array('REDIRECT_REMOTE_USER', 'identity', array('IDENTITY'), null, 200),
    'spoofed PHP auth header' => array('HTTP_PHP_AUTH_USER', 'identity', array('REJECT'), null, 200),
    'spoofed remote header' => array('HTTP_REMOTE_USER', 'identity', array('REJECT'), null, 200),
    'spoofed redirect header' => array('HTTP_REDIRECT_REMOTE_USER', 'identity', array('REJECT'), null, 200),
    'disabled remember cookie' => array('disabled', 'cookie', array('REJECT'), null, 200),
    'disabled legacy cookie' => array('disabled', 'cookie_legacy', array('REJECT'), null, 200),
    'locked remember cookie' => array('locked', 'cookie', array('REJECT'), null, 200),
    'locked legacy cookie' => array('locked', 'cookie_legacy', array('REJECT'), null, 200),
    'valid remember cookie still restores' => array('enabled', 'cookie', array('COOKIE_CHECK', 'LOGIN_LOG', 'CLEAR_REMEMBER', 'SET_REMEMBER', 'RESTORED'), null, 200),
    'valid legacy cookie still restores' => array('enabled', 'cookie_legacy', array('COOKIE_CHECK', 'LOGIN_LOG', 'CLEAR_REMEMBER', 'SET_REMEMBER', 'RESTORED'), null, 200),
    'normal disable save revokes credentials' => array('disabled_save', 'save', array('MESSAGE:1', '42:0,0,0', '43:1,1,1'), 1, 302),
    'plugin-finalized disable revokes credentials' => array('plugin_disabled', 'save', array('MESSAGE:1', '42:0,0,0', '43:1,1,1'), 1, 302),
    'enabled save retains credentials' => array('enabled', 'save', array('MESSAGE:1', '42:1,1,1', '43:1,1,1'), 1, 302),
    'failed save does not revoke credentials' => array('save_failed', 'save', array('MESSAGE:2', '42:1,1,1', '43:1,1,1'), 1, 302),
    'validation failure does not revoke credentials' => array('validation_error', 'save', array('42:1,1,1', '43:1,1,1'), 1, 302),
    'bulk disable retains revocation behavior' => array('disabled_save', 'bulk_disable', array('42:0,0,0', '43:1,1,1'), 1, 200),
    'failed password save retains credentials' => array('password_save_failed', 'save', array('MESSAGE:2', '42:1,1,1', '43:1,1,1'), 1, 302),
    'invalid password save retains credentials' => array('password_validation_error', 'save', array('42:1,1,1', '43:1,1,1'), 1, 302),
    'successful password save revokes remember tokens' => array('password_success', 'save', array('MESSAGE:1', '42:0,1,1', '43:1,1,1'), 1, 302),
    'failed forced reset retains credentials' => array('reset_save_failed', 'save', array('MESSAGE:2', '42:1,1,1', '43:1,1,1'), 1, 302),
    'invalid forced reset retains credentials' => array('reset_validation_error', 'save', array('42:1,1,1', '43:1,1,1'), 1, 302),
    'successful forced reset revokes credentials' => array('reset_success', 'save', array('MESSAGE:1', '42:0,0,0', '43:1,1,1'), 1, 302),
    'plugin-finalized reset revokes credentials' => array('plugin_reset', 'save', array('MESSAGE:1', '42:0,0,0', '43:1,1,1'), 1, 302),
));


test('realtime realm guard uses the real guest bootstrap and persisted permissions', function ($scenario, $mode, $allowed) {
    $root = dirname(__DIR__, 4);
    $dir = sys_get_temp_dir() . '/realtime-auth-' . bin2hex(random_bytes(8));
    mkdir($dir, 0700);
    foreach (['include', 'lib', 'cache'] as $child) mkdir($dir . '/' . $child, 0700);
    file_put_contents($dir . '/global.php', '<?php');
    // The real middleware is executed explicitly by the fixture; avoid a second initialization.
    file_put_contents($dir . '/include/auth.php', '<?php');
    file_put_contents($dir . '/lib/rrd.php', '<?php');
    file_put_contents($dir . '/cache/user_bootstrap_lgi_7.png', 'REALTIME_AUTHORIZED_IMAGE');
    $coverage = $this->getTestResultObject()->getCodeCoverage();
    $sources = ['include/csrf.php', 'include/vendor/csrf/csrf-conf.php', 'include/vendor/csrf/csrf-magic.php', 'include/auth.php', 'lib/auth.php', 'graph_realtime.php', 'user_admin.php', 'lib/functions.php',
        'lib/rrd.php', 'src/Graphing/Infrastructure/Rrd/ProxyCipher.php', 'lib/dsdebug.php', 'lib/rrd_maintenance.php', 'lib/poller.php', 'lib/boost.php', 'lib/api_data_source.php', 'lib/rrdcheck.php', 'lib/dsstats.php',
        'include/global_constants.php', 'tests/Helpers/PhpSource.php', 'tests/Helpers/NativeChildCoverageEvidence.php', 'tests/Fixtures/rrd-process-coverage.php'];
    $prelude = '';
    if ($coverage !== null) {
        require_once $root . '/tests/Helpers/NativeChildCoverageEvidence.php';
        $prelude = 'require_once ' . var_export($root . '/tests/Helpers/NativeChildCoverageEvidence.php', true) . ';'
            . '$GLOBALS["nativeChildCoverageSnapshot"] = NativeChildCoverageEvidence::snapshot('
            . var_export($root, true) . ',"tests/Fixtures/basic-auth-authorization.php",'
            . var_export($mode . '-' . $scenario, true) . ',' . var_export($sources, true) . ');'
            . 'define("BASIC_AUTH_TEST_COVERAGE",true);define("REALTIME_AUTH_TEST_COVERAGE",true);'
            . 'define("RRD_TEST_COVERAGE_DIRECTORY",' . var_export($dir, true) . ');'
            . 'require ' . var_export($root . '/tests/Fixtures/rrd-process-coverage.php', true) . ';';
    }
    try {
        $process = proc_open(
            [PHP_BINARY, '-d', 'include_path=' . $dir,
                '-d', 'pcov.directory=' . $root, '-d', 'pcov.exclude=~/(include/vendor|tests)/~', '-r',
                $prelude . 'require ' . var_export($root . '/tests/Fixtures/basic-auth-authorization.php', true) . ';', $root, $scenario, $mode],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $dir
        );
        expect(is_resource($process))->toBeTrue();
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);
        if ($exit !== 0) throw new RuntimeException($stderr . $stdout);
        expect($exit)->toBe(0);
        expect($stderr)->toBe('');
        $result = json_decode($stdout, true, 512, JSON_THROW_ON_ERROR);
        expect($result['user'])->toBe(42);
        expect($result['status'])->toBe($allowed ? 200 : 403);
        expect($result['body'])->toBe($allowed && $mode === 'realtime_view' ? base64_encode('REALTIME_AUTHORIZED_IMAGE') : (!$allowed && $mode === 'realtime_default' ? 'Permission Denied' : ''));
        expect($result['events'])->toBe($allowed && $mode === 'realtime_init' ? ['POLLER', 'RENDER'] : []);
        if ($coverage !== null) {
            $reports = glob($dir . '/*.coverage');
            expect($reports)->toHaveCount(1);
            $arguments = [$reports[0], $root, 'tests/Fixtures/basic-auth-authorization.php', $mode . '-' . $scenario,
                $sources, ['realtime-bootstrap-completed'], ['include/auth.php', 'lib/auth.php', 'graph_realtime.php']];
            $measured = NativeChildCoverageEvidence::load(...$arguments);
            expect(NativeChildCoverageEvidence::verifyRejections(...[...$arguments, 'lib/boost.php']))->toBe(count($sources) + 11);
            $coverage->merge($measured);
        }
    } finally {
        unlink($dir . '/global.php');
        unlink($dir . '/include/auth.php');
        unlink($dir . '/lib/rrd.php');
        unlink($dir . '/cache/user_bootstrap_lgi_7.png');
        foreach (glob($dir . '/*.coverage*') as $report) unlink($report);
        foreach (['include', 'lib', 'cache'] as $child) rmdir($dir . '/' . $child);
        rmdir($dir);
    }
})->with([
    'logged-in init beside configured guest denied' => ['existing_denied', 'realtime_init', false],
    'logged-in init beside configured guest allowed' => ['existing_allowed', 'realtime_init', true],
    'logged-in default beside configured guest denied' => ['existing_denied', 'realtime_default', false],
    'configured guest without realm' => ['guest_denied', 'realtime_view', false],
    'configured guest with realm' => ['guest_allowed', 'realtime_view', true],
    'guest with realm cannot read a denied graph' => ['guest_graph_denied', 'realtime_view', false],
    'guest graph exception admits the cached image' => ['guest_graph_allowed', 'realtime_view', true],
    'logged-in user with realm cannot read a denied graph' => ['existing_graph_denied', 'realtime_view', false],
    'logged-in graph exception admits the cached image' => ['existing_graph_allowed', 'realtime_view', true],
    'configured guest default denied' => ['guest_denied', 'realtime_default', false],
    'logged-in user beside configured guest denied' => ['existing_denied', 'realtime_view', false],
    'logged-in user beside configured guest allowed' => ['existing_allowed', 'realtime_view', true],
]);
