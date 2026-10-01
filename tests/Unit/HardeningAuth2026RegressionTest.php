<?php

/*
 * SPDX-FileCopyrightText: 2004-2026 The Cacti Group
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

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

test('GHSA-3jj2-v5ch-wmq5: domains_login_process uses >= 3 realm boundary', function () use ($authSource) {
    // realm=3 is a valid domain realm; > 3 would allow it to bypass the LDAP bind.
    // The guard and comment sit ~605-640 chars into the function body.
    $start = strpos($authSource, 'function domains_login_process(');
    expect($start)->not->toBeFalse();

    $body = substr($authSource, $start, 800);
    expect($body)->toContain('$realm >= 3');
});

test('GHSA-3jj2-v5ch-wmq5: realm boundary comment cites the advisory', function () use ($authSource) {
    $start = strpos($authSource, 'function domains_login_process(');
    expect($start)->not->toBeFalse();

    $body = substr($authSource, $start, 800);
    expect($body)->toContain('GHSA-3jj2-v5ch-wmq5');
});

// --- GHSA-2px8-gvmq-85f3: LDAP lockout call-site ---

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
            $coverage->merge(unserialize(file_get_contents($reports[0])));
        }
    } finally {
        foreach (glob($directory . '/*') as $file) {
            unlink($file);
        }
        rmdir($directory);
    }
})->with(array(array(1, 'Invalid credentials', true), array(2, '1', false), array(2, 'Directory unavailable', false)));
