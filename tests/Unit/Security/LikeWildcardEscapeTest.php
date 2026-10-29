<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

/*
 * db_qstr() escapes quotes, not LIKE metacharacters, so a filter of "%" matched
 * every row, and poller_automation.php concatenated a reverse-DNS name into a
 * LIKE clause. Upstream Cacti #7670. The production plugin list function runs
 * in a subprocess with a recording database stub.
 */

namespace Kadupul\Tests\Security\LikeWildcardEscape;

require_once dirname(__DIR__, 2) . '/Helpers/PhpSource.php';

$root = dirname(__DIR__, 3);
$source = file_get_contents($root . '/lib/database.php');
if (!is_string($source)) {
    throw new \RuntimeException('Unable to read lib/database.php.');
}
eval('namespace ' . __NAMESPACE__ . '; ' . test_php_function_source($source, 'db_like_escape')); // nosemgrep: php.lang.security.eval-use.eval-use

test('db_like_escape makes wildcards and the escape character literal', function (string $input, string $expected) {
    expect(db_like_escape($input))->toBe($expected);
})->with([
    'percent' => ['%', '\\%'],
    'underscore' => ['a_b', 'a\\_b'],
    'backslash' => ['a\\b', 'a\\\\b'],
    'plain' => ['router-1', 'router-1'],
    'all three' => ['%_\\', '\\%\\_\\\\'],
]);

/** @return array{queries: list<array{0: string, 1: list<string>}>} */
function runPluginList(string $filter): array
{
    $root = dirname(__DIR__, 3);
    $program = <<<'CHILD'
        $root = $argv[1];
        $filter = $argv[2];
        define('MAX_DISPLAY_PAGES', 5);
        require $root . '/tests/Helpers/PhpSource.php';
        $source = file_get_contents($root . '/plugins.php');
        $database = file_get_contents($root . '/lib/database.php');
        eval(test_php_function_source($source, 'update_show_current')); // nosemgrep: php.lang.security.eval-use.eval-use
        eval(test_php_function_source($database, 'db_like_escape')); // nosemgrep: php.lang.security.eval-use.eval-use
        $config = array('poller_id' => 1);
        $item_rows = array();
        $queries = array();
        class CactiSecureHeaders { public static function getNonceAttribute() { return ''; } }
        function plugin_clean_old_plugin_info() {}
        function validate_store_request_vars($filters, $session) {}
        function plugins_load_temp_table() { return 'plugin_temp'; }
        function get_request_var($name) {
            $values = array('filter' => $GLOBALS['filter'], 'state' => -99, 'rows' => 30, 'page' => 1, 'sort_column' => 'name', 'sort_direction' => 'ASC');
            return $values[$name] ?? '';
        }
        function isset_request_var($name) { return true; }
        function set_request_var($name, $value) {}
        function read_config_option($name) { return 30; }
        function get_order_string() { return 'ORDER BY name ASC'; }
        function __(...$a) { return $a[0]; }
        function __esc(...$a) { return $a[0]; }
        function html_escape($v) { return htmlspecialchars((string) $v); }
        function html_escape_request_var($n) { return html_escape(get_request_var($n)); }
        function cacti_sizeof($v) { return is_countable($v) ? count($v) : 0; }
        function html_start_box(...$a) {}
        function html_end_box(...$a) {}
        function html_header_sort(...$a) {}
        function html_nav_bar(...$a) { return ''; }
        function form_start(...$a) {}
        function form_end(...$a) {}
        function form_alternate_row(...$a) {}
        function form_end_row(...$a) {}
        function draw_actions_dropdown(...$a) {}
        function db_execute($sql) {}
        function db_fetch_cell($sql) { $GLOBALS['queries'][] = array($sql, array()); return 0; }
        function db_fetch_assoc($sql) { $GLOBALS['queries'][] = array($sql, array()); return array(); }
        function db_fetch_cell_prepared($sql, $params = array()) { $GLOBALS['queries'][] = array($sql, $params); return 0; }
        function db_fetch_assoc_prepared($sql, $params = array()) { $GLOBALS['queries'][] = array($sql, $params); return array(); }
        function db_qstr($s) { return "'" . addslashes((string) $s) . "'"; }
        ob_start();
        update_show_current();
        ob_end_clean();
        print json_encode($queries);
        CHILD;
    $process = proc_open(
        array(PHP_BINARY, '-d', 'display_errors=stderr', '-r', $program, $root, $filter),
        array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')),
        $pipes
    );
    if (!is_resource($process)) {
        throw new \RuntimeException('Unable to start the plugin list child process.');
    }
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $status = proc_close($process);
    expect($stderr)->toBe('')->and($status)->toBe(0);

    return array('queries' => json_decode($stdout, true, 512, JSON_THROW_ON_ERROR));
}

test('the plugin list binds the filter with LIKE wildcards escaped', function () {
    $queries = runPluginList("50%_\\' OR 1=1")['queries'];

    expect($queries)->toHaveCount(2);

    foreach ($queries as [$sql, $params]) {
        expect($sql)->toContain('LIKE ? OR')->and($sql)->not->toContain('OR 1=1');
        expect($params)->toBe(array("%50\\%\\_\\\\' OR 1=1%", "%50\\%\\_\\\\' OR 1=1%"));
    }
});

test('the plugin list without a filter binds nothing', function () {
    foreach (runPluginList('')['queries'] as [$sql, $params]) {
        expect($sql)->not->toContain('LIKE')->and($params)->toBe(array());
    }
});

test('the discovery sysName check binds the reverse-DNS name as an escaped LIKE value', function () {
    $source = file_get_contents(dirname(__DIR__, 3) . '/poller_automation.php');

    expect($source)->toContain("hostname LIKE ?")
        ->and($source)->toContain("db_like_escape(\$hostname) . '%'")
        ->and($source)->not->toContain('hostname LIKE "');
});
