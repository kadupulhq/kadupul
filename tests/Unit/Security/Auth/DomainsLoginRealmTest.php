<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

function domains_login_realm_run(PHPUnit\Framework\TestCase $test, string $mode, array $scenario): array
{
    $root = dirname(__DIR__, 4);
    $dir = sys_get_temp_dir() . '/domains-login-' . bin2hex(random_bytes(8));
    mkdir($dir, 0700);
    $coverage = $test->getTestResultObject()->getCodeCoverage();
    $prelude = '';
    if ($coverage !== null) {
        $prelude = 'define("DOMAINS_LOGIN_TEST_COVERAGE",true);'
            . 'define("RRD_TEST_COVERAGE_DIRECTORY",' . var_export($dir, true) . ');'
            . 'require ' . var_export($root . '/tests/Fixtures/rrd-process-coverage.php', true) . ';';
    }
    try {
        $process = proc_open(
            array(PHP_BINARY, '-d', 'error_reporting=24575', '-d', 'display_errors=stderr', '-d', 'pcov.directory=' . $root, '-d', 'pcov.exclude=~/(include/vendor|tests)/~', '-r', $prelude . 'require ' . var_export($root . '/tests/Fixtures/domains-login-realm.php', true) . ';', $root, $mode, json_encode($scenario, JSON_THROW_ON_ERROR)),
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
        if ($exit !== 0 || $stderr !== '') {
            throw new RuntimeException($stderr . $stdout);
        }
        if ($coverage !== null) {
            foreach (glob($dir . '/*.coverage') as $file) {
                $coverage->merge(unserialize(file_get_contents($file)));
            }
        }

        return json_decode($stdout, true, 512, JSON_THROW_ON_ERROR);
    } finally {
        foreach (glob($dir . '/*') as $file) {
            unlink($file);
        }
        rmdir($dir);
    }
}

function domains_login_realm_scenario(array $overrides): array
{
    return $overrides + array(
        'username' => 'alice',
        'request' => array('realm' => '1001', 'login_password' => 'secret'),
        'config' => array('auth_method' => '4', 'secpass_lockfailed' => '5'),
        'domains' => array(array('id' => 1, 'enabled' => 'on', 'template' => 0)),
        'users' => array(array('id' => 7, 'username' => 'alice', 'realm' => 1001)),
        'bind' => 0,
        'search' => 0,
    );
}

test('domains login only binds for an enabled domain realm', function ($overrides, $events, $error, $message, $user) {
    $result = domains_login_realm_run($this, 'process', domains_login_realm_scenario($overrides));

    expect($result['events'])->toBe($events)
        ->and($result['error'])->toBe($error)
        ->and($result['user']['id'] ?? null)->toBe($user);
    if (isset($overrides['cn'])) {
        expect($result['user']['full_name'])->toBe($overrides['cn']['displayName'] ?? '')
            ->and($result['user']['email_address'])->toBe($overrides['cn']['mail'] ?? '');
    }
    if ($message !== null) {
        expect($result['error_msg'])->toBe($message);
    }
})->with(array(
    'enabled domain with existing account' => array(array(), array('SEARCH', 'BIND'), false, null, 7),
    'Web Basic realm is not a domain' => array(array('request' => array('realm' => '2', 'login_password' => 'secret')), array('LOG_FAILED'), true, 'Access Denied!  Login Failed.', null),
    'LDAP realm is not a domain' => array(array('request' => array('realm' => '3', 'login_password' => 'secret')), array('LOG_FAILED'), true, 'Access Denied!  Login Failed.', null),
    'realm just below the domain range' => array(array('request' => array('realm' => '999', 'login_password' => 'secret')), array('LOG_FAILED'), true, 'Access Denied!  Login Failed.', null),
    'unconfigured domain id' => array(array('request' => array('realm' => '1002', 'login_password' => 'secret')), array('LOG_FAILED'), true, 'Access Denied!  Login Failed.', null),
    'disabled domain' => array(array('domains' => array(array('id' => 1, 'enabled' => '', 'template' => 0), array('id' => 2, 'enabled' => 'on', 'template' => 0))), array('LOG_FAILED'), true, 'Access Denied!  Login Failed.', null),
    'no enabled domain at all' => array(array('domains' => array(array('id' => 1, 'enabled' => '', 'template' => 0))), array('LOG_FAILED'), true, 'Access Denied!  Login Failed.', null),
    'missing realm' => array(array('request' => array('login_password' => 'secret')), array('LOG_FAILED'), true, 'Access Denied!  Login Failed.', null),
    'fractional realm' => array(array('request' => array('realm' => '1001.5', 'login_password' => 'secret')), array('INPUT_REJECTED'), false, null, null),
    'non-numeric realm' => array(array('request' => array('realm' => 'ldap', 'login_password' => 'secret')), array('INPUT_REJECTED'), false, null, null),
    'Local realm posted to the domains process' => array(array('request' => array('realm' => '0', 'login_password' => 'secret')), array('LOG_FAILED'), true, 'Access Denied!  Login Failed.', null),
    'empty username' => array(array('username' => ''), array('LOG_FAILED'), true, 'Access Denied!  Login Failed.', null),
    'empty password' => array(array('request' => array('realm' => '1001', 'login_password' => '')), array('LOG_FAILED', 'LOCKOUT_COUNT'), true, 'Access Denied!  Login Failed.', null),
    'empty password without a lockout policy' => array(array('request' => array('realm' => '1001', 'login_password' => ''), 'config' => array('auth_method' => '4')), array('LOG_FAILED'), true, 'Access Denied!  No password provided by user.', null),
    'locked account' => array(array('users' => array(array('id' => 7, 'username' => 'alice', 'realm' => 1001, 'locked' => 'on'))), array(), true, 'Your account has been locked.  Please contact your Administrator.', null),
    'rejected password' => array(array('bind' => 1), array('SEARCH', 'BIND', 'LOG_FAILED', 'LOCKOUT_COUNT'), true, 'Access Denied!  Login Failed.', null),
    'directory fault does not count toward lockout' => array(array('bind' => 9), array('SEARCH', 'BIND', 'LOG_FAILED'), true, 'Access Denied!  Login Failed.', null),
    'DN search failure' => array(array('search' => 14), array('SEARCH', 'LOG_FAILED'), true, 'Access Denied!  Login Failed.', null),
    'domain without LDAP settings' => array(array('domains' => array(array('id' => 1, 'enabled' => 'on', 'template' => 0, 'ldap' => false))), array('LOG_FAILED'), true, 'Access Denied!  Login Failed.', null),
    'bound user without account or domain template' => array(array('users' => array()), array('SEARCH', 'BIND', 'LOG_FAILED'), true, 'Access Denied!  Domain template is not configured.  Please contact your Administrator.', null),
    'new account copied from the domain template' => array(array('users' => array(array('id' => 5, 'username' => 'template', 'realm' => 0)), 'domains' => array(array('id' => 1, 'enabled' => 'on', 'template' => 5))), array('SEARCH', 'BIND', 'COPY'), false, null, 101),
    'new account takes directory name and mail' => array(array('users' => array(array('id' => 5, 'username' => 'template', 'realm' => 0)), 'domains' => array(array('id' => 1, 'enabled' => 'on', 'template' => 5, 'cn_full_name' => 'displayName', 'cn_email' => 'mail')), 'cn' => array('displayName' => 'Alice Example', 'mail' => 'alice@example.org')), array('SEARCH', 'BIND', 'CN', 'COPY'), false, null, 101),
    'new account when the directory has no name attributes' => array(array('users' => array(array('id' => 5, 'username' => 'template', 'realm' => 0)), 'domains' => array(array('id' => 1, 'enabled' => 'on', 'template' => 5, 'cn_full_name' => 'displayName'))), array('SEARCH', 'BIND', 'CN', 'COPY'), false, null, 101),
    'domain template user that no longer exists' => array(array('users' => array(), 'domains' => array(array('id' => 1, 'enabled' => 'on', 'template' => 44))), array('SEARCH', 'BIND', 'LOG_FAILED'), true, 'Access Denied!  Template user id 44 does not exist.  Please contact your Administrator.', null),
));

test('auth_login.php keeps domain logins out of the template and guest fallbacks', function ($overrides, $events, $error) {
    $result = domains_login_realm_run($this, 'login', $overrides + array(
        'username' => 'alice',
        'request' => array('action' => 'login', 'realm' => '1001', 'login_password' => 'secret'),
        'config' => array('auth_method' => '4'),
        'process_error' => false,
        'template' => 5,
        'guest' => 0,
    ));

    expect($result['events'])->toBe($events)
        ->and($result['error'])->toBe($error);
})->with(array(
    'domain process returned no account and no error' => array(array(), array('PROCESS', 'LOG_FAILED'), true),
    'domain process returned no account with guest configured' => array(array('template' => 0, 'guest' => 6), array('PROCESS', 'LOG_FAILED'), true),
    'domain process error is kept' => array(array('process_error' => true), array('PROCESS', 'LOG_FAILED'), true),
    'LDAP login still creates from the template' => array(array('config' => array('auth_method' => '3'), 'request' => array('action' => 'login', 'realm' => '2', 'login_password' => 'secret')), array('PROCESS', 'TEMPLATE'), false),
    'LDAP login still falls back to guest' => array(array('config' => array('auth_method' => '3'), 'request' => array('action' => 'login', 'realm' => '2', 'login_password' => 'secret'), 'template' => 0, 'guest' => 6), array('PROCESS', 'GUEST'), false),
    'Local realm in domains mode still uses the local path' => array(array('request' => array('action' => 'login', 'realm' => '0', 'login_password' => 'secret')), array('PROCESS', 'TEMPLATE'), false),
));

test('auth_login.php redraws the login form after a domain login when no domain is enabled', function () {
    $result = domains_login_realm_run($this, 'login', array(
        'username' => 'alice',
        'request' => array('action' => 'login', 'realm' => '1001', 'login_password' => 'secret'),
        'config' => array('auth_method' => '4'),
        'process_error' => true,
        'template' => 0,
        'guest' => 0,
        'render' => true,
    ));

    expect($result['events'])->toBe(array('PROCESS', 'LOG_FAILED', 'REALMS_RENDERED'))
        ->and($result['error'])->toBeTrue();
});


test('the login entry point chooses local only for configured local selector values', function ($method, $realm, $dispatches, $events) {
    $result = domains_login_realm_run($this, 'login', array(
        'username' => 'alice', 'request' => array('action' => 'login', 'realm' => $realm, 'login_password' => 'secret'),
        'config' => array('auth_method' => $method), 'process_error' => false, 'template' => 5, 'guest' => 6,
    ));
    expect($result['dispatches'])->toBe($dispatches)->and($result['events'])->toBe($events);
})->with(array(
    'Domains Local' => array('4', '0', array('local'), array('PROCESS', 'TEMPLATE')),
    'LDAP Local' => array('3', '1', array('local'), array('PROCESS', 'TEMPLATE')),
    'LDAP legacy Local' => array('3', '0', array('local'), array('PROCESS', 'TEMPLATE')),
    'LDAP selector' => array('3', '2', array('ldap'), array('PROCESS', 'TEMPLATE')),
    'Domain selector' => array('4', '1001', array('domain'), array('PROCESS', 'LOG_FAILED')),
    'unknown Domains Local selector' => array('4', '1', array('domain'), array('PROCESS', 'LOG_FAILED')),
    'negative Domains selector' => array('4', '-1', array(), array('INPUT_REJECTED')),
    'fractional Domains selector' => array('4', '0.5', array(), array('INPUT_REJECTED')),
    'array Domains selector' => array('4', array('0'), array(), array('INPUT_REJECTED')),
));
