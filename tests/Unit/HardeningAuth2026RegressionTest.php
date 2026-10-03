<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2004-2026 The Cacti Group
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

require_once dirname(__DIR__) . '/Helpers/PhpSource.php';
require_once dirname(__DIR__) . '/Helpers/NativeChildCoverageEvidence.php';

$authSource = file_get_contents(dirname(__DIR__, 2) . '/lib/auth.php');

// --- GHSA-9ffc-rr2g-c8hh: Remote-User header gate ---

test('GHSA-9ffc-rr2g-c8hh: get_basic_auth_username checks auth_method before reading headers', function () use ($authSource) {
    // The fix gates all header reads behind a read_config_option('auth_method') == 2
    // check; without it any proxy can spoof arbitrary usernames.
    $start = strpos($authSource, 'function get_basic_auth_username(');
    expect($start)->not->toBeFalse();

    $body = substr($authSource, $start, 600);
    expect($body)->toContain("read_config_option('auth_method')");
});

test('GHSA-9ffc-rr2g-c8hh: auth_method guard compares against 2', function () use ($authSource) {
    $start = strpos($authSource, 'function get_basic_auth_username(');
    expect($start)->not->toBeFalse();

    $body = substr($authSource, $start, 600);
    // Must use != 2 (or == 2) to gate the Basic-Auth method specifically.
    expect($body)->toContain("read_config_option('auth_method') != 2");
});

test('GHSA-9ffc-rr2g-c8hh: function returns false when auth_method is not 2', function () use ($authSource) {
    $start = strpos($authSource, 'function get_basic_auth_username(');
    expect($start)->not->toBeFalse();

    // The guard block: if (read_config_option('auth_method') != 2) { return false; }
    // returnfalse is at ~336 chars into the function; use 400 to be safe.
    $body = substr($authSource, $start, 400);
    expect($body)->toContain('return false;');
});

test('GHSA-9ffc-rr2g-c8hh: header reads only appear after the auth_method gate', function () use ($authSource) {
    $start = strpos($authSource, 'function get_basic_auth_username(');
    expect($start)->not->toBeFalse();

    $body = substr($authSource, $start, 1200);

    $guardPos = strpos($body, "read_config_option('auth_method') != 2");

    // Use $_SERVER['...'] forms to skip the mention in the docblock comment.
    $remoteUserPos = strpos($body, "\$_SERVER['REMOTE_USER']");
    $phpAuthPos    = strpos($body, "\$_SERVER['PHP_AUTH_USER']");

    // Guard must exist and must come before every actual server-variable access.
    expect($guardPos)->not->toBeFalse();
    expect($remoteUserPos)->toBeGreaterThan($guardPos);
    expect($body)->not->toContain("\$_SERVER['HTTP_REMOTE_USER']");
    expect($body)->not->toContain("\$_SERVER['HTTP_PHP_AUTH_USER']");
    expect($body)->not->toContain("\$_SERVER['HTTP_REDIRECT_REMOTE_USER']");
    expect($phpAuthPos)->toBeGreaterThan($guardPos);
});

// --- GHSA-3jj2-v5ch-wmq5: LDAP realm boundary ---

test('GHSA-3jj2-v5ch-wmq5: domains_login_process binds only for domain realms', function () use ($authSource) {
    // Domain realms are 1000 + domain_id. A lower bound of 3 let realms that
    // are not domains skip the bind; DomainsLoginRealmTest runs those cases.
    $body = test_php_function_source($authSource, 'domains_login_process');
    expect($body)->toContain('$realm >= 1000')
        ->and($body)->not->toContain('$realm >= 3');
});

test('GHSA-3jj2-v5ch-wmq5: domains_login_process checks the realm before lockout or bind', function () use ($authSource) {
    $body = test_php_function_source($authSource, 'domains_login_process');
    $allowlist = strpos($body, 'get_auth_realms(true)');
    $lockout = strpos($body, 'auth_checkclear_lockout(');
    $search = strpos($body, 'domains_ldap_search_dn(');

    expect($allowlist)->not->toBeFalse()
        ->and($lockout)->not->toBeFalse()
        ->and($search)->not->toBeFalse()
        ->and($allowlist)->toBeLessThan($lockout)
        ->and($allowlist)->toBeLessThan($search);
});

// --- GHSA-2px8-gvmq-85f3: LDAP lockout call-site ---

test('GHSA-2px8-gvmq-85f3: lockout condition uses error_num not error_text', function () use ($authSource) {
    // error_text is a human-readable string; using it in a numeric comparison
    // always evaluates to zero (false), silently skipping the lockout call.
    $body = test_php_function_source($authSource, 'domains_login_process');
    expect($body)->toContain('$ldap_auth_response[\'error_num\'] == 1');
});

test('GHSA-2px8-gvmq-85f3: error_num == 1 appears adjacent to auth_process_lockout', function () use ($authSource) {
    $body = test_php_function_source($authSource, 'domains_login_process');

    $errorNumPos = strpos($body, "'error_num'] == 1");
    $lockoutPos  = strpos($body, 'auth_process_lockout(');

    expect($errorNumPos)->not->toBeFalse();
    expect($lockoutPos)->not->toBeFalse();
    // The lockout call must follow closely (within 150 chars) after the condition.
    expect($lockoutPos - $errorNumPos)->toBeLessThan(150);
});

test('GHSA-2px8-gvmq-85f3: error_text is not used in a numeric comparison inside domains_login_process', function () use ($authSource) {
    $body = test_php_function_source($authSource, 'domains_login_process');
    // The pre-fix bug was 'error_text' == 1; that pattern must not exist.
    expect($body)->not->toContain("'error_text'] == 1");
});
test('domain bind failures enforce the configured lockout using numeric error codes', function (int $code, string $text, bool $locked) {
    $directory = sys_get_temp_dir() . '/domain-lockout-' . bin2hex(random_bytes(8));
    mkdir($directory, 0700);
    $coverage = $this->getTestResultObject()->getCodeCoverage();
    try {
        $command = array(PHP_BINARY, '-d', 'auto_prepend_file=', '-d', 'error_reporting=24575', '-d', 'pcov.directory=/', '-d', 'pcov.exclude=~/(include/vendor|tests)/~', dirname(__DIR__) . '/Fixtures/domain-lockout-native.php', (string) $code, $text);
        $environment = array_merge(getenv(), array('DOMAIN_LOCKOUT_COVERAGE_DIRECTORY' => $coverage === null ? '' : $directory));
        $process = proc_open($command, array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes, null, $environment);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        expect(proc_close($process))->toBe(0)->and($stderr)->toBe('');
        $state = json_decode($stdout, true, 512, JSON_THROW_ON_ERROR);
        expect($state['first'])->toBe(array())->and($state['second'])->toBe(array())->and($state['third'])->toBe(array())
            ->and((int) $state['after_first']['failed_attempts'])->toBe($locked ? 1 : 0)
            ->and($state['after_first']['locked'])->toBe('')
            ->and((int) $state['after_second']['failed_attempts'])->toBe($locked ? 2 : 0)
            ->and($state['after_second']['locked'])->toBe($locked ? 'on' : '')
            ->and($state['binds'])->toBe($locked ? 2 : 3)
            ->and($state['error'])->toBeTrue()->and((int) $state['other'])->toBe(0);
        if ($locked) {
            expect($state['message'])->toContain('locked');
        }
        if ($coverage !== null) {
            $reports = glob($directory . '/*.coverage');
            expect($reports)->toHaveCount(1);
            $root = dirname(__DIR__, 2);
            $sources = array('tests/Unit/HardeningAuth2026RegressionTest.php', 'composer.lock', 'tests/composer.lock', 'tests/Fixtures/domain-lockout-native.php', 'tests/Fixtures/rrd-process-coverage.php', 'tests/Helpers/NativeChildCoverageEvidence.php', 'lib/auth.php', 'lib/rrd.php', 'src/Graphing/Infrastructure/Rrd/ProxyCipher.php', 'lib/dsdebug.php', 'lib/rrd_maintenance.php', 'lib/poller.php', 'lib/boost.php', 'lib/api_data_source.php', 'lib/rrdcheck.php', 'lib/dsstats.php');
            $scenario = json_encode(array('code' => (string) $code, 'text' => $text), JSON_THROW_ON_ERROR);
            $arguments = array($reports[0], $root, 'tests/Fixtures/domain-lockout-native.php', $scenario, $sources, array('domain-lockout-persisted-state-readback'), array('lib/auth.php'));
            $measured = NativeChildCoverageEvidence::load(...$arguments);
            static $verifiedOmissions = false;
            if (!$verifiedOmissions) {
                expect(NativeChildCoverageEvidence::verifyRejections(...array_merge($arguments, array('lib/boost.php'))))->toBe(count($sources) + 11);
                $verifiedOmissions = true;
            }
            $coverage->merge($measured);
        }
    } finally {
        foreach (glob($directory . '/*') as $file) {
            unlink($file);
        }
        rmdir($directory);
    }
})->with(array(array(1, 'Invalid credentials', true), array(2, '1', false), array(2, 'Directory unavailable', false)));
