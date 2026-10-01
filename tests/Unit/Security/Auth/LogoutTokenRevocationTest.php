<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

require_once dirname(__DIR__, 3) . '/Helpers/ChildProcessCoverage.php';

/*
 * Every logout, including the automatic timeout, suspension and Remote Data
 * Collector ones, must delete the server-side remember-me token. Expiring the
 * browser cookie alone leaves a copied cookie value usable.
 */

function logout_run(string $action, $cookie, bool $enabled = true, bool $bound = false): array
{
    $root = dirname(__DIR__, 4);
    $dir = sys_get_temp_dir() . '/kadupul-logout-' . bin2hex(random_bytes(6));
    mkdir($dir . '/include', 0700, true);

    $stubs = <<<'PHP'
<?php
define('OPER_MODE_NATIVE', 0);
define('OPER_MODE_RESKIN', 2);
define('COPYRIGHT_YEARS_SHORT', '2004-2026');
$config = array('url_path' => '/kadupul/');
$GLOBALS['events'] = array();
$GLOBALS['cache_db'] = new PDO('sqlite::memory:', null, null, array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
$GLOBALS['cache_db']->sqliteCreateFunction('NOW', static fn() => '2026-10-01 00:00:00');
$GLOBALS['cache_db']->exec('CREATE TABLE user_auth_cache (user_id INTEGER, hostname TEXT, last_update TEXT, token TEXT)');
$GLOBALS['cache_db']->exec("INSERT INTO user_auth_cache VALUES (43, '192.0.2.11', '', 'unrelated-token')");
function read_config_option($name, $force = false) { return $name === 'auth_cache_enabled' && $GLOBALS['cache_enabled'] ? 'on' : '';  }
function db_table_exists($name) { return $name === 'user_auth_cache'; }
function db_fetch_cell_prepared($sql, $params = array()) { return false; }
function db_execute_prepared($sql, $params = array()) {
    $GLOBALS['events'][] = array('sql' => trim(preg_replace('/\s+/', ' ', $sql)), 'params' => $params);
    return $GLOBALS['cache_db']->prepare($sql)->execute($params);
}
function cacti_sizeof($array) { return is_array($array) ? count($array) : 0; }
function get_client_addr() { return '192.0.2.10'; }
function cacti_cookie_session_set($id,$realm,$token) {}
function cacti_cookie_session_logout() { $GLOBALS['events'][] = 'cookie_session_logout'; }
function cacti_cookie_logout() { $GLOBALS['events'][] = 'cookie_logout'; }
function cacti_session_destroy() { $GLOBALS['events'][] = 'session_destroy'; }
function get_cacti_version() { return '1.3.0'; }
function api_plugin_hook($name) {}
function api_plugin_hook_function($name, $arg = null) { return $arg; }
function set_default_action($default = '') {}
function get_request_var($name, $default = '') { return $_GET[$name] ?? $default; }
function __($text, ...$args) { return vsprintf($text, $args); }
function html_common_header($title) {}
class CactiSecureHeaders { public static function getNonceAttribute() { return ''; } }
register_shutdown_function(function () {
    $output = ob_get_clean();
    print json_encode(array('events' => $GLOBALS['events'], 'output' => $output, 'bound' => $GLOBALS['bound'] ?? null, 'cache_users' => $GLOBALS['cache_db']->query('SELECT DISTINCT user_id FROM user_auth_cache ORDER BY user_id')->fetchAll(PDO::FETCH_COLUMN)));
});
ob_start();
PHP;
    $stubs .= "\nrequire_once " . var_export($root . '/lib/auth.php', true) . ";\n";

    $stubs .= "\n";
    if ($bound) {
        $stubs .= 'set_auth_cookie(array("id"=>42,"realm"=>0));$GLOBALS["bound"]=$_SESSION["sess_remember_token"]??null;$GLOBALS["events"]=array();';
    }
    file_put_contents($dir . '/include/auth.php', $stubs);
    file_put_contents($dir . '/include/global_session.php', "<?php\n");

    $program = '$GLOBALS["cache_enabled"] = ' . ($enabled ? 'true;' : 'false;') . '$_GET["action"] = $argv[1];'
        . 'if ($argv[2] !== "") { $_COOKIE["cacti_remembers"] = json_decode($argv[2], true); }'
        . 'require ' . var_export($root . '/logout.php', true) . ';';

    try {
        $process = proc_open(
            child_coverage_command(array(PHP_BINARY, '-d', 'display_errors=stderr', '-d', 'error_reporting=' . E_ALL, '-r', $program, $action, $cookie === null ? '' : json_encode($cookie)), $coverage_dir),
            array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')),
            $pipes,
            $dir
        );
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);
        child_coverage_collect($coverage_dir);
    } finally {
        unlink($dir . '/include/auth.php');
        unlink($dir . '/include/global_session.php');
        rmdir($dir . '/include');
        rmdir($dir);
    }

    $result = json_decode($stdout, true);
    expect($result)->toBeArray();
    $result['stderr'] = $stderr;

    return $result;
}

function logout_token_deletes(array $result): array
{
    return array_values(array_filter($result['events'], function ($event): bool {
        return is_array($event) && strpos($event['sql'], 'DELETE FROM user_auth_cache') === 0;
    }));
}

test('every logout path deletes the server-side remember-me token first', function (string $action) {
    $result = logout_run($action, '42,0,remember-me-token');
    $deletes = logout_token_deletes($result);

    expect($result['stderr'])->toBe('')
        ->and($deletes)->toHaveCount(1)
        ->and($deletes[0]['params'])->toBe(array('42', hash('sha512', 'remember-me-token')))
        ->and(array_search($deletes[0], $result['events'], true))->toBeLessThan(array_search('cookie_logout', $result['events'], true));
})->with(array('timeout', 'disabled', 'remote', 'default' => ''));

test('a logout without a remember-me cookie deletes nothing', function (string $action) {
    $result = logout_run($action, null);

    expect($result['stderr'])->toBe('')
        ->and(logout_token_deletes($result))->toBe(array())
        ->and($result['events'])->toContain('cookie_logout');
})->with(array('timeout', 'default' => ''));

test('a malformed remember-me cookie is dropped without an error', function ($cookie) {
    $result = logout_run('timeout', $cookie);

    expect($result['stderr'])->toBe('')
        ->and(logout_token_deletes($result))->toBe(array())
        ->and($result['events'])->toContain('cookie_session_logout')
        ->and($result['events'])->toContain('cookie_logout');
})->with(array(
    'array' => array(array('42', 'token')),
    'one part' => '42',
    'four parts' => '42,0,token,extra',
));

test('each automatic logout explains why the user was logged out', function (string $action, string $reason) {
    $result = logout_run($action, null);

    expect($result['stderr'])->toBe('')
        ->and($result['output'])->toContain('<p>' . $reason . '</p>');
})->with(array(
    'timeout' => array('timeout', 'You have been logged out of Kadupul due to a session timeout.'),
    'disabled' => array('disabled', 'You have been logged out of Kadupul due to an account suspension.'),
    'remote' => array('remote', 'You have been logged out of Kadupul due to a Remote Data Collector state change'),
));

test('logout revokes an old token after remember-me is disabled', function () {
    expect(logout_token_deletes(logout_run('timeout', '42,0,remember-me-token', false)))->toHaveCount(1);
});


test('logout revokes the issued session token after its browser cookie is missing or malformed', function ($cookie) {
    $result = logout_run('timeout', $cookie, true, true);
    $deletes = logout_token_deletes($result);
    expect($result['stderr'])->toBe('')->and($deletes)->toHaveCount(1)
        ->and($deletes[0]['params'])->toBe(array(42, $result['bound']['hash']))
        ->and($result['cache_users'])->toBe(array(43))
        ->and(array_search($deletes[0], $result['events'], true))->toBeLessThan(array_search('cookie_logout', $result['events'], true));
})->with(array('missing' => array(null), 'malformed' => array('42'), 'array' => array(array('invalid'))));
