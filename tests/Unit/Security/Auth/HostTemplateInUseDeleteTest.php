<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-2.0-or-later

$root = dirname(__DIR__, 4);

/* Runs the production form_actions() from host_templates.php in a child
   process. Device Template 3 has two Devices; 4 and 5 have none. */
$runAction = function ($drpAction, array $items) use ($root) {
    $program = <<<'PHP'
namespace HostTemplateInUseRuntime;

$GLOBALS['request'] = array(
    'drp_action'     => $argv[1],
    'selected_items' => $argv[2],
    'title_format'   => '<template_title> (1)',
);
$_SESSION['sess_user_id'] = 7;

$templates = file_get_contents(getcwd() . '/host_templates.php');
$functions = file_get_contents(getcwd() . '/lib/functions.php');
$database  = file_get_contents(getcwd() . '/lib/database.php');

$code = array();
foreach (array(
    array($templates, 'form_actions'),
    array($templates, 'host_templates_without_devices'),
    array($functions, 'sanitize_unserialize_selected_items'),
    array($functions, 'selected_items_decode'),
    array($database, 'array_to_sql_or'),
) as $wanted) {
    if (preg_match('/^function ' . $wanted[1] . '\(.*?^}\n/ms', $wanted[0], $match)) {
        $code[] = $match[0];
    } elseif ($wanted[1] !== 'host_templates_without_devices') {
        exit(2);
    }
}

const MESSAGE_LEVEL_ERROR = 3;

function get_filter_request_var($name) { return $GLOBALS['request'][$name]; }
function get_nfilter_request_var($name) { return $GLOBALS['request'][$name]; }
function get_request_var($name) { return $GLOBALS['request'][$name]; }
function isset_request_var($name) { return isset($GLOBALS['request'][$name]); }
function cacti_count($value) { return is_array($value) ? count($value) : 0; }
function cacti_sizeof($value) { return cacti_count($value); }
function db_execute($sql) { echo 'SQL:' . $sql . "\n"; }
function db_fetch_cell_prepared($sql, $params = array()) {
    if (preg_match('/^SELECT COUNT\(\*\)\s+FROM host\s+WHERE host_template_id = \?$/', trim($sql))) {
        /* MySQL reads '3e0', ' 3' and '03' as 3 too. */
        return (int) $params[0] === 3 ? 2 : 0;
    }

    echo 'UNEXPECTED:' . $sql . "\n";

    return 0;
}
function api_duplicate_device_template($id, $title) { echo 'DUPLICATE:' . $id . "\n"; }
function api_device_template_sync_template($id) { echo 'SYNC:' . $id . "\n"; }
function cacti_log($message, $output = false, $facility = '') { echo 'LOG:' . $facility . ':' . $message . "\n"; }
function raise_message($id, $message = '', $level = 0) { echo 'MESSAGE:' . $id . "\n"; }
function header($value) { echo 'HEADER:' . $value . "\n"; }
function __($text) { return $text; }

// array_to_sql_or() passes db_qstr to array_map() by name, which resolves globally.
eval('function db_qstr($value) { return "\'" . addslashes($value) . "\'"; }');
eval('namespace HostTemplateInUseRuntime; ' . implode("\n", $code));

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

$inUseIds = array('3', '3e0', ' 3', '03', '3.0');

test('a forged delete never removes a device template that devices use', function () use ($runAction, $inUseIds) {
    foreach ($inUseIds as $inUse) {
        list($exit, $stdout, $stderr) = $runAction('1', array($inUse, '4'));

        expect($exit)->toBe(0, $stderr)
            ->and($stdout)->toContain("SQL:DELETE FROM host_template WHERE (id IN('4'))")
            ->and($stdout)->toContain("SQL:UPDATE host SET host_template_id = 0 WHERE deleted = \"\" AND (host_template_id IN('4'))")
            ->and($stdout)->not->toContain(addslashes($inUse) . "'")
            ->and($stdout)->toContain('LOG:WEBUI:WARNING: Refused to delete Device Template 3 used by 2 Device(s) for user 7')
            ->and($stdout)->toContain('MESSAGE:host_template_in_use')
            ->and($stdout)->not->toContain('UNEXPECTED:');
    }
});

test('a forged delete of only in-use device templates changes nothing', function () use ($runAction, $inUseIds) {
    foreach ($inUseIds as $inUse) {
        list($exit, $stdout, $stderr) = $runAction('1', array($inUse));

        expect($exit)->toBe(0, $stderr)
            ->and($stdout)->not->toContain('SQL:')
            ->and($stdout)->toContain('MESSAGE:host_template_in_use')
            ->and($stdout)->toContain('HEADER:Location: host_templates.php?header=false');
    }
});

test('unused device templates are still deleted, and in-use ones still duplicate and sync', function () use ($runAction) {
    list($exit, $stdout, $stderr) = $runAction('1', array('4', '5'));

    expect($exit)->toBe(0, $stderr)
        ->and($stdout)->toContain("SQL:DELETE FROM host_template WHERE (id IN('4','5'))")
        ->and($stdout)->toContain("SQL:DELETE FROM host_template_graph WHERE (host_template_id IN('4','5'))")
        ->and($stdout)->not->toContain('Refused');

    list($exit, $stdout, $stderr) = $runAction('2', array('3'));

    expect($exit)->toBe(0, $stderr)
        ->and($stdout)->toContain('DUPLICATE:3')
        ->and($stdout)->not->toContain('Refused');

    list($exit, $stdout, $stderr) = $runAction('3', array('3'));

    expect($exit)->toBe(0, $stderr)
        ->and($stdout)->toContain('SYNC:3')
        ->and($stdout)->not->toContain('Refused');
});
