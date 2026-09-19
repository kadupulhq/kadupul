<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-2.0-or-later

$root = dirname(__DIR__, 4);

/* Runs one production template_item_* function from host_templates.php in a
   child process against a small in-memory database:
   device templates 3 and 5, graph template 7, data query graph template 8,
   data query 9, and template 3 holding graph template 7 and data query 9. */
$runItem = function ($function, array $request) use ($root) {
    $program = <<<'PHP'
namespace HostTemplateItemRuntime;

$GLOBALS['request'] = json_decode($argv[2], true);
$_SESSION['sess_user_id'] = 7;

$source = file_get_contents(getcwd() . '/host_templates.php');

$code = array();
foreach (array($argv[1], 'template_item_refuse') as $name) {
    if (preg_match('/^function ' . $name . '\(.*?^}\n/ms', $source, $match)) {
        $code[] = $match[0];
    } elseif ($name === $argv[1]) {
        exit(2);
    }
}

const MESSAGE_LEVEL_ERROR = 3;

$GLOBALS['db'] = array(
    'host_template'            => array(3, 5),
    'graph_templates'          => array(7, 8),
    'snmp_query_graph'         => array(8),
    'snmp_query'               => array(9),
    'host_template_graph'      => array(array(3, 7)),
    'host_template_snmp_query' => array(array(3, 9)),
);

function get_request_var($name) { return isset($GLOBALS['request'][$name]) ? $GLOBALS['request'][$name] : ''; }
function get_filter_request_var($name) { return get_request_var($name); }
function cacti_log($message, $output = false, $facility = '') { echo 'LOG:' . $facility . ':' . $message . "\n"; }
function raise_message($id, $message = '', $level = 0) { echo 'MESSAGE:' . $id . "\n"; }
function __($text) { return $text; }
function db_execute_prepared($sql, $params) { echo 'SQL:' . preg_replace('/\s+/', ' ', trim($sql)) . ':' . implode(',', $params) . "\n"; }

/* Answers each lookup by the tables it names. Every lookup binds the device
   template first and the item second. */
function db_fetch_cell_prepared($sql, $params = array()) {
    $db = $GLOBALS['db'];
    list($ht, $item) = array_map('intval', $params);

    if (strpos($sql, 'host_template_graph') !== false) {
        return in_array(array($ht, $item), $db['host_template_graph'], true) ? 1 : 0;
    }

    if (strpos($sql, 'host_template_snmp_query') !== false) {
        return in_array(array($ht, $item), $db['host_template_snmp_query'], true) ? 1 : 0;
    }

    if (strpos($sql, 'snmp_query_graph') !== false) {
        return in_array($ht, $db['host_template'], true) && in_array($item, $db['graph_templates'], true)
            && !in_array($item, $db['snmp_query_graph'], true) ? 1 : 0;
    }

    if (strpos($sql, 'snmp_query') !== false) {
        return in_array($ht, $db['host_template'], true) && in_array($item, $db['snmp_query'], true) ? 1 : 0;
    }

    echo 'UNEXPECTED:' . $sql . "\n";

    return 0;
}

eval('namespace HostTemplateItemRuntime; ' . implode("\n", $code));

$function = __NAMESPACE__ . '\\' . $argv[1];
$function();
PHP;

    $process = proc_open(
        array(PHP_BINARY, '-r', $program, $function, json_encode((object) $request)),
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

$expectRefused = function ($runItem, $function, array $request) {
    list($exit, $stdout, $stderr) = $runItem($function, $request);

    expect($exit)->toBe(0, $stderr)
        ->and($stdout)->not->toContain('SQL:')
        ->and($stdout)->not->toContain('UNEXPECTED:')
        ->and($stdout)->not->toContain('MESSAGE:41')
        ->and($stdout)->toContain('LOG:WEBUI:WARNING: Refused to ')
        ->and($stdout)->toContain('for user 7')
        ->and($stdout)->toContain('MESSAGE:host_template_item_refused');
};

test('a graph template is attached only to an existing device template, and never a data query graph', function () use ($runItem, $expectRefused) {
    foreach (array(array('0', '7'), array('4', '7'), array('3', '6'), array('3', '8'), array('', '7')) as list($ht, $gt)) {
        $expectRefused($runItem, 'template_item_add_gt', array('host_template_id' => $ht, 'graph_template_id' => $gt));
    }

    list($exit, $stdout, $stderr) = $runItem('template_item_add_gt', array('host_template_id' => '5', 'graph_template_id' => '7'));

    expect($exit)->toBe(0, $stderr)
        ->and($stdout)->toContain('SQL:REPLACE INTO host_template_graph (host_template_id, graph_template_id) VALUES (?, ?):5,7')
        ->and($stdout)->toContain('MESSAGE:41')
        ->and($stdout)->not->toContain('Refused');
});

test('a data query is attached only to an existing device template', function () use ($runItem, $expectRefused) {
    foreach (array(array('0', '9'), array('4', '9'), array('3', '10')) as list($ht, $sq)) {
        $expectRefused($runItem, 'template_item_add_dq', array('host_template_id' => $ht, 'snmp_query_id' => $sq));
    }

    list($exit, $stdout, $stderr) = $runItem('template_item_add_dq', array('host_template_id' => '5', 'snmp_query_id' => '9'));

    expect($exit)->toBe(0, $stderr)
        ->and($stdout)->toContain('SQL:REPLACE INTO host_template_snmp_query (host_template_id, snmp_query_id) VALUES (?, ?):5,9')
        ->and($stdout)->toContain('MESSAGE:41')
        ->and($stdout)->not->toContain('Refused');
});

test('removing a graph template or data query is refused unless the named device template holds it', function () use ($runItem, $expectRefused) {
    $expectRefused($runItem, 'template_item_remove_gt', array('host_template_id' => '5', 'id' => '7'));
    $expectRefused($runItem, 'template_item_remove_gt', array('host_template_id' => '3', 'id' => '8'));
    $expectRefused($runItem, 'template_item_remove_dq', array('host_template_id' => '5', 'id' => '9'));
    $expectRefused($runItem, 'template_item_remove_dq', array('host_template_id' => '3', 'id' => '10'));

    list($exit, $stdout, $stderr) = $runItem('template_item_remove_gt', array('host_template_id' => '3', 'id' => '7'));

    expect($exit)->toBe(0, $stderr)
        ->and($stdout)->toContain('SQL:DELETE FROM host_template_graph WHERE graph_template_id = ? AND host_template_id = ?:7,3')
        ->and($stdout)->toContain('MESSAGE:41')
        ->and($stdout)->not->toContain('Refused');

    list($exit, $stdout, $stderr) = $runItem('template_item_remove_dq', array('host_template_id' => '3', 'id' => '9'));

    expect($exit)->toBe(0, $stderr)
        ->and($stdout)->toContain('SQL:DELETE FROM host_template_snmp_query WHERE snmp_query_id = ? AND host_template_id = ?:9,3')
        ->and($stdout)->toContain('MESSAGE:41')
        ->and($stdout)->not->toContain('Refused');
});
