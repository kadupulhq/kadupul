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
    'valid remember cookie still restores' => array('enabled', 'cookie', array('COOKIE_CHECK', 'LOGIN_LOG', 'CLEAR_REMEMBER', 'SET_REMEMBER', 'RESTORED'), null, 200),
    'valid legacy cookie still restores' => array('enabled', 'cookie_legacy', array('COOKIE_CHECK', 'LOGIN_LOG', 'CLEAR_REMEMBER', 'SET_REMEMBER', 'RESTORED'), null, 200),
    'normal disable save revokes credentials' => array('disabled_save', 'save', array('MESSAGE:1', '42:0,0,0', '43:1,1,1'), 1, 302),
    'plugin-finalized disable revokes credentials' => array('plugin_disabled', 'save', array('MESSAGE:1', '42:0,0,0', '43:1,1,1'), 1, 302),
    'enabled save retains credentials' => array('enabled', 'save', array('MESSAGE:1', '42:1,1,1', '43:1,1,1'), 1, 302),
    'failed save does not revoke credentials' => array('save_failed', 'save', array('MESSAGE:2', '42:1,1,1', '43:1,1,1'), 1, 302),
    'validation failure does not revoke credentials' => array('validation_error', 'save', array('42:1,1,1', '43:1,1,1'), 1, 302),
    'bulk disable retains revocation behavior' => array('disabled_save', 'bulk_disable', array('42:0,0,0', '43:1,1,1'), 1, 200),
));
