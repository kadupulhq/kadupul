<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

/*
 * LDAPS and StartTLS must check the directory's certificate unless an
 * administrator chose otherwise, and the upgrade must keep installs that
 * already ran without the check working. The shipped lib/ldap.php and upgrade
 * script run in a child process against stubbed ldap_* and database calls.
 */

require_once dirname(__DIR__, 3) . '/Helpers/PhpSource.php';
require_once dirname(__DIR__, 3) . '/Helpers/ChildProcessCoverage.php';

function ldap_tls_child(string $program, array $scenario): array
{
    $pipes = array();
    $process = proc_open(
        child_coverage_command(array(PHP_BINARY, '-d', 'disable_functions=ldap_set_option,ldap_connect,ldap_error', '-d', 'display_errors=stderr', '-r', $program, json_encode($scenario)), $coverage_dir),
        array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')),
        $pipes
    );
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);
    child_coverage_collect($coverage_dir);

    expect($stderr)->toBe('');

    return json_decode($stdout, true);
}

function ldap_tls_connect(array $settings, string $encryption): array
{
    $root = dirname(__DIR__, 4);

    $program = '$root = ' . var_export($root, true) . ';' . <<<'PHP'
$scenario = json_decode($argv[1], true);
$GLOBALS['settings'] = $scenario['settings'];
$GLOBALS['options'] = array();
if (!defined('LDAP_OPT_X_TLS_REQUIRE_CERT')) { define('LDAP_OPT_X_TLS_REQUIRE_CERT', 0x6006); }
if (!defined('LDAP_OPT_PROTOCOL_VERSION')) { define('LDAP_OPT_PROTOCOL_VERSION', 17); }
require $root . '/include/global_constants.php';
function read_config_option($name, $force = false) { return $GLOBALS['settings'][$name] ?? ''; }
function get_selective_log_level() { return 0; }
function cacti_log(...$args) {}
function cacti_debug_backtrace(...$args) { return ''; }
function __($text, ...$args) { return vsprintf($text, $args); }
function ldap_set_option($conn, $option, $value) { $GLOBALS['options'][] = array($option, $value); return true; }
function ldap_connect(...$args) { return false; }
function ldap_error($conn) { return ''; }
require $root . '/lib/ldap.php';
$ldap = new Ldap();
$ldap->username = 'alice';
$ldap->Connect();
$require = null;
foreach ($GLOBALS['options'] as $option) {
    if ($option[0] === LDAP_OPT_X_TLS_REQUIRE_CERT) {
        $require = $option[1];
    }
}
print json_encode(array('require' => $require, 'env' => getenv('TLS_REQCERT')));
PHP;

    return ldap_tls_child($program, array('settings' => $settings + array('ldap_encryption' => $encryption)));
}

function ldap_tls_upgrade(array $settings, int $encrypted_domains): array
{
    $source = file_get_contents(dirname(__DIR__, 4) . '/install/upgrades/1_2_31.php');

    $program = <<<'PHP'
$scenario = json_decode($argv[1], true);
$GLOBALS['scenario'] = $scenario;
$GLOBALS['writes'] = array();
define('LDAP_OPT_X_TLS_NEVER', 0);
function db_install_fetch_cell($sql, $params = array(), $log = true) {
    if (strpos($sql, 'user_domains_ldap') !== false) {
        return array('status' => 1, 'data' => (string) $GLOBALS['scenario']['domains']);
    }
    return array('status' => 1, 'data' => $GLOBALS['scenario']['settings'][$params[0]] ?? false);
}
function db_install_execute($sql, $params = array(), $log = true) { $GLOBALS['writes'][] = array($sql, $params); return 1; }
PHP;

    $program .= "\n" . test_php_function_source($source, 'upgrade_ldap_tls_requirement') . "\n";
    $program .= 'upgrade_ldap_tls_requirement(); print json_encode($GLOBALS[\'writes\']);';

    return ldap_tls_child($program, array('settings' => $settings, 'domains' => $encrypted_domains));
}

function ldap_tls_prime(string $version, array $stored, int $encrypted_domains): array
{
    $root = dirname(__DIR__, 4);

    $program = '$root = ' . var_export($root, true) . ';' . <<<'PHP'
$scenario = json_decode($argv[1], true);
$GLOBALS['scenario'] = $scenario;
$GLOBALS['writes'] = array();
$GLOBALS['config'] = array('cacti_db_version' => $scenario['version']);
$GLOBALS['settings'] = array('authentication' => array(
    'ldap_encryption' => array('default' => 0),
    'ldap_tls_certificate' => array('default' => 2),
));
require $root . '/include/global_constants.php';
function cacti_sizeof($value) { return is_array($value) ? count($value) : 0; }
function db_fetch_cell_prepared($sql, $params = array()) { return $GLOBALS['scenario']['stored'][$params[0]] ?? false; }
function db_fetch_cell($sql) { return (string) $GLOBALS['scenario']['domains']; }
function db_execute_prepared($sql, $params = array()) { $GLOBALS['writes'][$params[0]] = $params[1]; return true; }
require $root . '/install/functions.php';
prime_default_settings();
print json_encode($GLOBALS['writes']);
PHP;

    return ldap_tls_child($program, array('version' => $version, 'stored' => $stored, 'domains' => $encrypted_domains));
}

/*
 * Replays login attempts against an in-memory settings table. Each step is
 * 'login', which runs the login page's settle step and then an LDAP connect,
 * or array(name, value), which an administrator saves. A domain login sets
 * the domain's encryption on the connection, as domains_ldap_auth() does.
 */
function ldap_tls_logins(array $settings, int $encrypted_domains, array $steps, string $domain_encryption = ''): array
{
    $root = dirname(__DIR__, 4);

    $program = '$root = ' . var_export($root, true) . ';' . <<<'PHP'
$scenario = json_decode($argv[1], true);
$GLOBALS['settings'] = $scenario['settings'];
$GLOBALS['writes'] = array();
$GLOBALS['options'] = array();
if (!defined('LDAP_OPT_X_TLS_REQUIRE_CERT')) { define('LDAP_OPT_X_TLS_REQUIRE_CERT', 0x6006); }
if (!defined('LDAP_OPT_PROTOCOL_VERSION')) { define('LDAP_OPT_PROTOCOL_VERSION', 17); }
require $root . '/include/global_constants.php';
function read_config_option($name, $force = false) { return $GLOBALS['settings'][$name] ?? ''; }
function db_fetch_cell_prepared($sql, $params = array()) { return $GLOBALS['settings'][$params[0]] ?? false; }
function db_fetch_cell($sql) { return (string) $GLOBALS['scenario_domains']; }
function db_execute_prepared($sql, $params = array()) {
    $GLOBALS['writes'][] = $params;
    // INSERT ... ON DUPLICATE KEY UPDATE value = IF(value = '', new, value)
    if (($GLOBALS['settings'][$params[0]] ?? '') === '') {
        $GLOBALS['settings'][$params[0]] = $params[1];
    }
    return true;
}
function get_selective_log_level() { return 0; }
function cacti_log(...$args) {}
function cacti_debug_backtrace(...$args) { return ''; }
function __($text, ...$args) { return vsprintf($text, $args); }
function ldap_set_option($conn, $option, $value) { $GLOBALS['options'][] = array($option, $value); return true; }
function ldap_connect(...$args) { return false; }
function ldap_error($conn) { return ''; }
$GLOBALS['scenario_domains'] = $scenario['domains'];
require $root . '/lib/ldap.php';
$required = array();
foreach ($scenario['steps'] as $step) {
    if (is_array($step)) {
        $GLOBALS['settings'][$step[0]] = $step[1];
        continue;
    }
    cacti_ldap_tls_settle_requirement();
    $GLOBALS['options'] = array();
    $ldap = new Ldap();
    $ldap->username = 'alice';
    if ($scenario['domain_encryption'] !== '') {
        $ldap->encryption = $scenario['domain_encryption'];
    }
    $ldap->Connect();
    $level = null;
    foreach ($GLOBALS['options'] as $option) {
        if ($option[0] === LDAP_OPT_X_TLS_REQUIRE_CERT) {
            $level = $option[1];
        }
    }
    $required[] = $level;
}
print json_encode(array('required' => $required, 'writes' => $GLOBALS['writes'], 'stored' => $GLOBALS['settings']['ldap_tls_certificate'] ?? null));
PHP;

    return ldap_tls_child($program, array('settings' => $settings, 'domains' => $encrypted_domains, 'steps' => $steps, 'domain_encryption' => $domain_encryption));
}

test('an install already at 1.2.31 that uses LDAP encryption keeps Never after its first login', function (array $settings, int $domains, string $domain_encryption = '') {
    $result = ldap_tls_logins($settings, $domains, array('login', 'login'), $domain_encryption);

    expect($result['stored'])->toBe('0')
        ->and($result['required'])->toBe(array(0, 0))
        ->and($result['writes'])->toBe(array(array('ldap_tls_certificate', '0')));
})->with(array(
    'LDAPS' => array(array('ldap_encryption' => '1'), 0),
    'StartTLS' => array(array('ldap_encryption' => '2'), 0),
    'an empty saved row' => array(array('ldap_encryption' => '1', 'ldap_tls_certificate' => ''), 0),
    'a domain' => array(array('ldap_encryption' => '0'), 1, '1'),
));

test('a login never replaces a saved requirement', function (string $saved, int $level) {
    $result = ldap_tls_logins(array('ldap_encryption' => '1', 'ldap_tls_certificate' => $saved), 1, array('login'));

    expect($result['stored'])->toBe($saved)
        ->and($result['required'])->toBe(array($level))
        ->and($result['writes'])->toBe(array());
})->with(array('demand' => array('2', 2), 'hard' => array('1', 1), 'never' => array('0', 0)));

test('a fresh install gets Demand and keeps it when LDAP encryption is turned on later', function () {
    $result = ldap_tls_logins(array('ldap_encryption' => '0'), 0, array('login', array('ldap_encryption', '1'), 'login'));

    expect($result['stored'])->toBe('2')
        ->and($result['required'])->toBe(array(null, 2))
        ->and($result['writes'])->toBe(array(array('ldap_tls_certificate', '2')));
});

test('the login page settles the requirement before any directory login', function () {
    $source = file_get_contents(dirname(__DIR__, 4) . '/auth_login.php');
    $block = strpos($source, "if (get_nfilter_request_var('action') == 'login' || \$auth_method == 2) {");
    $settle = strpos($source, 'cacti_ldap_tls_settle_requirement();');

    expect($block)->not->toBeFalse()
        ->and($settle)->toBeGreaterThan($block)
        ->and($settle)->toBeLessThan(strpos($source, "switch (\$auth_method) {", $block));
});

test('an install that never saved the requirement checks the certificate', function (string $encryption) {
    $result = ldap_tls_connect(array(), $encryption);

    expect($result['require'])->toBe(2)
        ->and($result['env'])->toBe('demand');
})->with(array('LDAPS' => '1', 'StartTLS' => '2'));

test('an unreadable saved requirement falls back to checking the certificate', function (string $saved) {
    $result = ldap_tls_connect(array('ldap_tls_certificate' => $saved), '1');

    expect($result['require'])->toBe(2);
})->with(array('empty' => '', 'text' => 'never', 'out of range' => '9'));

test('an administrator can still relax or tighten the requirement', function (string $saved, int $level, string $env) {
    $result = ldap_tls_connect(array('ldap_tls_certificate' => $saved), '1');

    expect($result['require'])->toBe($level)
        ->and($result['env'])->toBe($env);
})->with(array(
    'never' => array('0', 0, 'never'),
    'hard' => array('1', 1, 'hard'),
    'allow' => array('3', 3, 'allow'),
    'try' => array('4', 4, 'try'),
));

test('a cleartext connection sets no certificate requirement', function () {
    $result = ldap_tls_connect(array(), '0');

    expect($result['require'])->toBeNull();
});

test('the shipped setting defaults to Demand', function () {
    $source = file_get_contents(dirname(__DIR__, 4) . '/include/global_settings.php');

    $start = strpos($source, "'ldap_tls_certificate' => array(");
    $field = substr($source, $start, strpos($source, '$ldap_tls_cert_req', $start) - $start);

    expect($start)->not->toBeFalse()
        ->and($field)->toContain("'default' => LDAP_OPT_X_TLS_DEMAND,");
});

test('the upgrade keeps Never for an install that used LDAPS without a saved requirement', function () {
    $writes = ldap_tls_upgrade(array('ldap_encryption' => '1'), 0);

    expect($writes)->toHaveCount(1)
        ->and($writes[0][1])->toBe(array('ldap_tls_certificate', '0'));
});

test('the upgrade keeps Never when only a domain uses encryption', function () {
    $writes = ldap_tls_upgrade(array('ldap_encryption' => '0', 'ldap_tls_certificate' => ''), 2);

    expect($writes)->toHaveCount(1)
        ->and($writes[0][1])->toBe(array('ldap_tls_certificate', '0'));
});

test('the upgrade leaves a saved requirement alone', function (string $saved) {
    expect(ldap_tls_upgrade(array('ldap_encryption' => '2', 'ldap_tls_certificate' => $saved), 1))->toBe(array());
})->with(array('never' => '0', 'demand' => '2'));

test('the upgrade gives installs without LDAP encryption the new default', function () {
    expect(ldap_tls_upgrade(array(), 0))->toBe(array())
        ->and(ldap_tls_upgrade(array('ldap_encryption' => '0'), 0))->toBe(array());
});

test('the web installer primes Never for an existing install that uses LDAP encryption', function (array $stored, int $domains) {
    $writes = ldap_tls_prime('1.2.31', $stored, $domains);

    expect($writes['ldap_tls_certificate'])->toBe(0);
})->with(array(
    'LDAPS' => array(array('ldap_encryption' => '1'), 0),
    'a domain' => array(array('ldap_encryption' => '0'), 1),
));

test('the web installer primes Demand for a new install or one without LDAP encryption', function (string $version, array $stored) {
    $writes = ldap_tls_prime($version, $stored, 0);

    expect($writes['ldap_tls_certificate'])->toBe(2);
})->with(array(
    'new install' => array('new_install', array('ldap_encryption' => '1')),
    'no encryption' => array('1.2.31', array('ldap_encryption' => '0')),
));

test('the web installer leaves a stored requirement alone', function () {
    $writes = ldap_tls_prime('1.2.31', array('ldap_encryption' => '1', 'ldap_tls_certificate' => '2'), 0);

    expect($writes)->not->toHaveKey('ldap_tls_certificate');
});
