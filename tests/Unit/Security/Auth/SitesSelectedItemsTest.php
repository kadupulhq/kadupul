<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-2.0-or-later

$root = dirname(__DIR__, 4);

/* Runs the production form_actions() and duplicate_site() from sites.php in a
   child process. Sites 3, 4 and 5 exist; the largest list page offers 5000
   rows, as include/global_arrays.php does. */
$runAction = function ($drpAction, array $items) use ($root) {
    $program = <<<'PHP'
namespace SitesSelectedItemsRuntime;

$GLOBALS['request'] = array(
    'drp_action'     => $argv[1],
    'selected_items' => $argv[2],
    'site_name'      => '<site> (1)',
);
$_SESSION['sess_user_id'] = 7;

$sites     = file_get_contents(getcwd() . '/sites.php');
$functions = file_get_contents(getcwd() . '/lib/functions.php');
$database  = file_get_contents(getcwd() . '/lib/database.php');

$code = array();
foreach (array(
    array($sites, 'form_actions'),
    array($sites, 'duplicate_site'),
    array($sites, 'sites_selected_ids'),
    array($functions, 'sanitize_unserialize_selected_items'),
    array($functions, 'array_rekey'),
    array($database, 'array_to_sql_or'),
) as $wanted) {
    if (preg_match('/^function ' . $wanted[1] . '\(.*?^}\n/ms', $wanted[0], $match)) {
        $code[] = $match[0];
    } elseif ($wanted[1] !== 'sites_selected_ids') {
        exit(2);
    }
}

const MESSAGE_LEVEL_ERROR = 3;

$GLOBALS['item_rows'] = array(10 => '10', 5000 => '5000');
$GLOBALS['sites']     = array(3, 4, 5);

function get_filter_request_var($name) { return $GLOBALS['request'][$name]; }
function get_nfilter_request_var($name) { return $GLOBALS['request'][$name]; }
function get_request_var($name) { return $GLOBALS['request'][$name]; }
function isset_request_var($name) { return isset($GLOBALS['request'][$name]); }
function cacti_count($value) { return is_array($value) ? count($value) : 0; }
function cacti_sizeof($value) { return cacti_count($value); }
function db_execute($sql) { echo 'SQL:' . $sql . "\n"; }
function db_fetch_assoc_prepared($sql, $params = array()) {
    echo 'LOOKUP:' . count($params) . "\n";

    $rows = array();
    foreach ($params as $id) {
        if (in_array((int) $id, $GLOBALS['sites'], true)) {
            $rows[] = array('id' => (int) $id);
        }
    }

    return $rows;
}
function db_fetch_row_prepared($sql, $params = array()) {
    return in_array((int) $params[0], $GLOBALS['sites'], true) ? array('id' => (int) $params[0], 'name' => 'Site ' . (int) $params[0]) : array();
}
function sql_save($save, $table) { echo 'COPY:' . $save['name'] . "\n"; return 9; }
function set_config_option($name, $value) {}
function cacti_log($message, $output = false, $facility = '') { echo 'LOG:' . $facility . ':' . $message . "\n"; }
function raise_message($id, $message = '', $level = 0) { echo 'MESSAGE:' . $id . "\n"; }
function header($value) { echo 'HEADER:' . $value . "\n"; }
function __($text) { return $text; }

// array_to_sql_or() passes db_qstr to array_map() by name, which resolves globally.
eval('function db_qstr($value) { return "\'" . addslashes($value) . "\'"; }');
eval('namespace SitesSelectedItemsRuntime; ' . implode("\n", $code));

form_actions();
PHP;

    $process = proc_open(
        array(PHP_BINARY, '-r', $program, $drpAction, serialize($items)),
        array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')),
        $pipes,
        $root
    );

    expect(is_resource($process))->toBeTrue();

    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return array(proc_close($process), $stdout, $stderr);
};

$expectRefused = function ($runAction, $drpAction, array $items, $log, $message) {
    list($exit, $stdout, $stderr) = $runAction($drpAction, $items);

    expect($exit)->toBe(0, $stderr)
        ->and($stdout)->not->toContain('SQL:')
        ->and($stdout)->not->toContain('COPY:')
        ->and($stdout)->toContain('LOG:WEBUI:WARNING: ' . $log)
        ->and($stdout)->toContain('for user 7')
        ->and($stdout)->toContain('MESSAGE:' . $message)
        ->and($stdout)->toContain('HEADER:Location: sites.php?header=false');
};

test('a site delete or duplicate naming an id that is not an existing site changes nothing', function () use ($runAction, $expectRefused) {
    foreach (array('1', '2') as $drpAction) {
        foreach (array(array('3', '6'), array('6'), array('3e0'), array(' 3'), array('03'), array('3.0'), array(''), array('0'), array('-3')) as $items) {
            $expectRefused($runAction, $drpAction, $items, 'Refused a Site action naming', 'site_unknown');
        }
    }
});

test('a site duplicate larger than the largest list page changes nothing', function () use ($runAction, $expectRefused) {
    $expectRefused($runAction, '2', array_fill(0, 5001, '3'), 'Refused to duplicate 5001 Sites', 'site_duplicate_limit');

    list($exit, $stdout, $stderr) = $runAction('2', array_fill(0, 5000, '3'));

    expect($exit)->toBe(0, $stderr)
        ->and(substr_count($stdout, 'COPY:Site 3 (1)'))->toBe(5000)
        ->and($stdout)->not->toContain('Refused');
});

test('existing sites are still deleted and duplicated, and a large delete is not capped', function () use ($runAction) {
    list($exit, $stdout, $stderr) = $runAction('1', array('3', '5'));

    expect($exit)->toBe(0, $stderr)
        ->and($stdout)->toContain("SQL:DELETE FROM sites WHERE (id IN('3','5'))")
        ->and($stdout)->toContain("SQL:UPDATE host SET site_id=0 WHERE deleted=\"\" AND (site_id IN('3','5'))")
        ->and($stdout)->not->toContain('Refused');

    list($exit, $stdout, $stderr) = $runAction('2', array(4, '5'));

    expect($exit)->toBe(0, $stderr)
        ->and($stdout)->toContain('COPY:Site 4 (1)')
        ->and($stdout)->toContain('COPY:Site 5 (1)')
        ->and($stdout)->not->toContain('Refused');

    list($exit, $stdout, $stderr) = $runAction('1', array_merge(array_fill(0, 6000, '4'), array('3')));

    expect($exit)->toBe(0, $stderr)
        ->and($stdout)->toContain('SQL:DELETE FROM sites WHERE')
        ->and($stdout)->not->toContain('Refused');
});
