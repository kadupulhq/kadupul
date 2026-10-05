<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

if (PHP_SAPI !== 'cli') {
    exit(1);
}
$root = dirname(__DIR__, 2);
require_once $root . '/tests/Helpers/NativeChildCoverageEvidence.php';
if (getenv('DOMAIN_LOCKOUT_COVERAGE_DIRECTORY')) {
    $sources = array('tests/Unit/HardeningAuth2026RegressionTest.php', 'composer.lock', 'tests/composer.lock', 'tests/Fixtures/domain-lockout-native.php', 'tests/Fixtures/rrd-process-coverage.php', 'tests/Helpers/NativeChildCoverageEvidence.php', 'lib/auth.php', 'lib/graph_item_choices.php', 'lib/rrd.php', 'src/Graphing/Infrastructure/Rrd/ProxyCipher.php', 'lib/dsdebug.php', 'lib/rrd_maintenance.php', 'lib/poller.php', 'lib/boost.php', 'lib/api_data_source.php', 'lib/rrdcheck.php', 'lib/dsstats.php');
    $scenario = json_encode(array('code' => $argv[1], 'text' => $argv[2]), JSON_THROW_ON_ERROR);
    $GLOBALS['nativeCoverageEvidence'] = NativeChildCoverageEvidence::snapshot($root, 'tests/Fixtures/domain-lockout-native.php', $scenario, $sources);
}

// Only the LDAP transport is substituted. Login, realm selection and lockout
// execute from the actual production file against persistent SQLite rows.
#[AllowDynamicProperties]
class Ldap
{
    public function Search()
    {
        return array('error_num' => 0, 'dn' => 'uid=alice,dc=example');
    }

    public function Authenticate()
    {
        $GLOBALS['binds']++;
        return array('error_num' => (int) $GLOBALS['argv'][1], 'error_text' => $GLOBALS['argv'][2]);
    }
}

define('POLLER_VERBOSITY_DEBUG', 5);
define('POLLER_VERBOSITY_LOW', 2);
$db = new PDO('sqlite::memory:', options: array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
$db->exec("CREATE TABLE user_auth(id INTEGER PRIMARY KEY,username TEXT,realm INTEGER,enabled TEXT,locked TEXT,password TEXT,lastfail INTEGER,failed_attempts INTEGER)");
$db->exec("INSERT INTO user_auth VALUES(42,'alice',1003,'on','','',0,0),(43,'other',1003,'on','','',0,0)");
$db->exec("CREATE TABLE user_domains(domain_id INTEGER,domain_name TEXT,enabled TEXT,defdomain INTEGER); INSERT INTO user_domains VALUES(3,'Fixture','on',1)");
$db->exec('CREATE TABLE user_log(username TEXT,user_id INTEGER,result INTEGER,ip TEXT,time TEXT)');
function db_fetch_row_prepared($sql, $params = array())
{
    if (str_contains($sql, 'FROM user_domains_ldap')) {
        return array('server' => 'fixture.example', 'group_require' => '');
    }
    $q = $GLOBALS['db']->prepare($sql);
    $q->execute($params);
    return $q->fetch(PDO::FETCH_ASSOC) ?: array();
}
function db_fetch_cell_prepared($sql, $params = array())
{
    $q = $GLOBALS['db']->prepare($sql);
    $q->execute($params);
    return $q->fetchColumn();
}
function db_execute_prepared($sql, $params = array())
{
    $sql = str_replace(array('INSERT IGNORE', 'NOW()'), array('INSERT OR IGNORE', 'CURRENT_TIMESTAMP'), $sql);
    $q = $GLOBALS['db']->prepare($sql);
    return $q->execute($params);
}
function read_config_option($name)
{
    return $name === 'secpass_lockfailed' ? 2 : ($name === 'auth_method' ? 4 : 0);
}
function get_nfilter_request_var($name)
{
    return $name === 'realm' ? 1003 : 'wrong-password';
}
function get_filter_request_var($name)
{
    return (int) get_nfilter_request_var($name);
}
function db_fetch_assoc($sql)
{
    return $GLOBALS["db"]->query($sql)->fetchAll(PDO::FETCH_ASSOC);
}
function db_fetch_cell($sql)
{
    return db_fetch_cell_prepared($sql);
}
function cacti_sizeof($rows)
{
    return count($rows);
}
function cacti_log(...$args) {}
function get_client_addr()
{
    return '127.0.0.1';
}
function __($message, ...$args)
{
    return $args ? vsprintf($message, $args) : $message;
}

if (getenv('DOMAIN_LOCKOUT_COVERAGE_DIRECTORY')) {
    define('AUTH_POLICY_TEST_COVERAGE', true);
    define('RRD_TEST_COVERAGE_DIRECTORY', getenv('DOMAIN_LOCKOUT_COVERAGE_DIRECTORY'));
    require __DIR__ . '/rrd-process-coverage.php';
}
require dirname(__DIR__, 2) . '/lib/auth.php';
$error = false;
$error_msg = '';
$binds = 0;
$first = domains_login_process('alice');
$after_first = $db->query('SELECT failed_attempts,locked FROM user_auth WHERE id=42')->fetch(PDO::FETCH_ASSOC);
$error = false;
$second = domains_login_process('alice');
$after_second = $db->query('SELECT failed_attempts,locked FROM user_auth WHERE id=42')->fetch(PDO::FETCH_ASSOC);
$error = false;
$third = domains_login_process('alice');
$encoded = json_encode(array('first' => $first, 'second' => $second, 'third' => $third, 'after_first' => $after_first, 'after_second' => $after_second, 'binds' => $binds, 'error' => $error, 'message' => $error_msg, 'other' => $db->query('SELECT failed_attempts FROM user_auth WHERE id=43')->fetchColumn()), JSON_THROW_ON_ERROR);
define('NATIVE_COVERAGE_COMPLETED', array('domain-lockout-persisted-state-readback'));
print $encoded;
