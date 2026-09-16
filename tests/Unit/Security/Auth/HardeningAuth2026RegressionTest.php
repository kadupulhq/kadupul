<?php
require_once dirname(__DIR__, 3) . "/Helpers/PhpSource.php";
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 | Copyright (C) 2026 The Kadupul project and contributors                 |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDtool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
*/

$authSource = file_get_contents(dirname(__DIR__, 4) . '/lib/auth.php');

// --- GHSA-9ffc-rr2g-c8hh: Remote-User header gate ---

test('GHSA-9ffc-rr2g-c8hh: get_basic_auth_username checks auth_method before reading headers', function () use ($authSource) {
    // The fix gates all header reads behind a read_config_option('auth_method') == 2
    // check; without it any proxy can spoof arbitrary usernames.
    $body = test_php_function_source($authSource, 'get_basic_auth_username');
    expect($body)->toContain("read_config_option('auth_method')");
});

test('GHSA-9ffc-rr2g-c8hh: auth_method guard compares against 2', function () use ($authSource) {
    $body = test_php_function_source($authSource, 'get_basic_auth_username');
    // Must use != 2 (or == 2) to gate the Basic-Auth method specifically.
    expect($body)->toContain("read_config_option('auth_method') != 2");
});

test('GHSA-9ffc-rr2g-c8hh: function returns false when auth_method is not 2', function () use ($authSource) {
    $body = test_php_function_source($authSource, 'get_basic_auth_username');
    expect($body)->toContain('return false;');
});

test('GHSA-9ffc-rr2g-c8hh: header reads only appear after the auth_method gate', function () use ($authSource) {
    $body = test_php_function_source($authSource, 'get_basic_auth_username');

    $guardPos = strpos($body, "read_config_option('auth_method') != 2");

    // Use $_SERVER['...'] forms to skip the mention in the docblock comment.
    $remoteUserPos = strpos($body, "\$_SERVER['REMOTE_USER']");

    // Guard must exist and must come before the server-variable read; the
    // client-controlled variants are not read at all.
    expect($guardPos)->not->toBeFalse();
    expect($remoteUserPos)->toBeGreaterThan($guardPos);
    expect($body)->not->toContain("\$_SERVER['HTTP_REMOTE_USER']");
    expect(strpos($body, "\$_SERVER['PHP_AUTH_USER']"))->toBeGreaterThan($guardPos);
});

// --- GHSA-3jj2-v5ch-wmq5: LDAP realm boundary ---

test('GHSA-3jj2-v5ch-wmq5: domains_login_process binds only for Domains realms of 1000 and up', function () use ($authSource) {
    // Domains realms are 1000 + domain_id; a lower posted realm must not reach
    // the template or guest fallback without an LDAP bind.
    $body = test_php_function_source($authSource, 'domains_login_process');
    expect($body)->toContain("\$realm >= 1000 && \$password != ''");
});

test('GHSA-3jj2-v5ch-wmq5: a posted realm must be one the login page lists before any bind', function () use ($authSource) {
    $body = test_php_function_source($authSource, 'domains_login_process');
    $listed = strpos($body, 'array_key_exists($realm, get_auth_realms(true))');

    expect($listed)->not->toBeFalse();
    expect(strpos($body, '$realm >= 1000'))->toBeGreaterThan($listed);
});

// --- GHSA-2px8-gvmq-85f3: LDAP lockout call-site ---

test('GHSA-2px8-gvmq-85f3: lockout condition uses error_num not error_text', function () use ($authSource) {
    // error_text is a human-readable string; using it in a numeric comparison
    // always evaluates to zero (false), silently skipping the lockout call.
    // The condition sits ~4865 chars into the function; use 5200 to be safe.
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
