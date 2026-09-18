<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-2.0-or-later

$root = dirname(__DIR__, 4);

$runAction = function ($drpAction, array $items) use ($root) {
    $program = <<<'PHP'
namespace PollersMainCollectorRuntime;

$GLOBALS['request'] = array(
    'drp_action'     => $argv[1],
    'selected_items' => $argv[2],
);
$_SESSION['sess_user_id'] = 7;

$pollers   = file_get_contents(getcwd() . '/pollers.php');
$functions = file_get_contents(getcwd() . '/lib/functions.php');
$database  = file_get_contents(getcwd() . '/lib/database.php');

$code = array();
foreach (array(
    array($pollers, 'form_actions'),
    array($pollers, 'pollers_without_main'),
    array($functions, 'sanitize_unserialize_selected_items'),
    array($database, 'array_to_sql_or'),
) as $wanted) {
    if (preg_match('/^function ' . $wanted[1] . '\(.*?^}\n/ms', $wanted[0], $match)) {
        $code[] = $match[0];
    } elseif ($wanted[1] !== 'pollers_without_main') {
        exit(2);
    }
}

const MESSAGE_LEVEL_ERROR = 3;

function get_filter_request_var($name) { return $GLOBALS['request'][$name]; }
function get_nfilter_request_var($name) { return $GLOBALS['request'][$name]; }
function get_request_var($name) { return $GLOBALS['request'][$name]; }
function isset_request_var($name) { return isset($GLOBALS['request'][$name]); }
function cacti_sizeof($value) { return is_array($value) ? count($value) : 0; }
function db_execute($sql) { echo 'SQL:' . $sql . "\n"; }
function db_execute_prepared($sql, $params) { echo 'SQL:' . $sql . ':' . implode(',', $params) . "\n"; }
function cacti_log($message, $output = false, $facility = '') { echo 'LOG:' . $facility . ':' . $message . "\n"; }
function raise_message($id, $message = '', $level = 0) { echo 'MESSAGE:' . $id . "\n"; }
function header($value) { echo 'HEADER:' . $value . "\n"; }
function __($text) { return $text; }

// array_to_sql_or() passes db_qstr to array_map() by name, which resolves globally.
eval('function db_qstr($value) { return "\'" . addslashes($value) . "\'"; }');
eval('namespace PollersMainCollectorRuntime; ' . implode("\n", $code));

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

/* Each spelling reaches id 1 in MySQL once array_to_sql_or() quotes it. */
$mainIds = array('1', '1e0', ' 1', '01', '1.0');

test('a forged delete never removes the main data collector', function () use ($runAction, $mainIds) {
    foreach ($mainIds as $main) {
        list($exit, $stdout, $stderr) = $runAction('1', array($main, '3'));

        expect($exit)->toBe(0, $stderr)
            ->and($stdout)->toContain("SQL:DELETE FROM poller WHERE (id IN('3'))")
            ->and($stdout)->not->toContain(addslashes($main) . "'")
            ->and($stdout)->toContain('LOG:WEBUI:WARNING: Refused to delete the main Data Collector')
            ->and($stdout)->toContain('MESSAGE:poller_keep_main');
    }
});

test('a forged delete of only the main data collector changes nothing', function () use ($runAction, $mainIds) {
    foreach ($mainIds as $main) {
        list($exit, $stdout, $stderr) = $runAction('1', array($main));

        expect($exit)->toBe(0, $stderr)
            ->and($stdout)->not->toContain('SQL:')
            ->and($stdout)->toContain('HEADER:Location: pollers.php?header=false');
    }
});

test('a forged disable never disables the main data collector', function () use ($runAction, $mainIds) {
    foreach ($mainIds as $main) {
        list($exit, $stdout, $stderr) = $runAction('2', array($main, '3'));

        expect($exit)->toBe(0, $stderr)
            ->and($stdout)->toContain("SQL:UPDATE poller SET disabled=\"on\" WHERE (id IN('3'))")
            ->and($stdout)->not->toContain(addslashes($main) . "'")
            ->and($stdout)->toContain('LOG:WEBUI:WARNING: Refused to disable the main Data Collector');
    }
});

test('remote data collectors can still be deleted and the main one re-enabled', function () use ($runAction) {
    list($exit, $stdout, $stderr) = $runAction('1', array('3', '4'));

    expect($exit)->toBe(0, $stderr)
        ->and($stdout)->toContain("SQL:DELETE FROM poller WHERE (id IN('3','4'))")
        ->and($stdout)->not->toContain('Refused');

    list($exit, $stdout, $stderr) = $runAction('3', array('1'));

    expect($exit)->toBe(0, $stderr)
        ->and($stdout)->toContain("SQL:UPDATE poller SET disabled=\"\" WHERE (id IN('1'))")
        ->and($stdout)->not->toContain('Refused');
});
