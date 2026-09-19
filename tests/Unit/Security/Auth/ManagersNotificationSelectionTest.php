<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

$root = dirname(__DIR__, 4);

/* Runs managers.php form_actions() for a submitted notification selection in a
   child PHP process, because the handler ends with exit. Each database write
   is printed so the test sees which pairs reached snmpagent_managers_notifications. */
$runSelection = function ($drpAction, $selectedItems) use ($root) {
    $program = <<<'PHP'
namespace ManagersNotificationRuntime;

$GLOBALS['request'] = array(
    'action_receiver_notifications' => '1',
    'selected_items'                => $argv[2],
    'drp_action'                    => $argv[1],
    'id'                            => '7',
);

$source = file_get_contents(getcwd() . '/managers.php');

if (!preg_match('/^function form_actions\(\).*?^}\n/ms', $source, $handler)) {
    exit(2);
}

preg_match('/^function managers_cached_notification_pairs\(.*?^}\n/ms', $source, $helper);

function isset_request_var($name) { return isset($GLOBALS['request'][$name]); }
function get_nfilter_request_var($name) { return isset($GLOBALS['request'][$name]) ? $GLOBALS['request'][$name] : ''; }
function get_request_var($name) { return get_nfilter_request_var($name); }
function get_filter_request_var($name) { return (int) get_nfilter_request_var($name); }
function cacti_unserialize($value) { return @unserialize($value, array('allowed_classes' => false)); }
function cacti_sizeof($value) { return is_countable($value) ? count($value) : 0; }
function cacti_log($message, $output = false, $facility = '') { echo 'LOG:' . $facility . ':' . $message . "\n"; }
function header($value) { echo 'HEADER:' . $value . "\n"; }
function db_fetch_assoc($sql) {
    if (strpos($sql, "kind = 'Notification'") === false) {
        return array();
    }

    return array(
        array('mib' => 'CACTI-MIB', 'name' => 'cactiNotifyDeviceDown'),
        array('mib' => 'CACTI-MIB', 'name' => 'cactiNotifyDeviceRecovering'),
    );
}
function db_execute_prepared($sql, $params = array()) {
    echo 'SQL:' . strtok(trim($sql), ' ') . ':' . implode('|', $params) . "\n";
}

if (!empty($helper[0])) {
    eval('namespace ManagersNotificationRuntime; ' . $helper[0]);
}

eval('namespace ManagersNotificationRuntime; ' . $handler[0]);

form_actions();
PHP;

    $process = proc_open(
        array(PHP_BINARY, '-r', $program, $drpAction, serialize($selectedItems)),
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

test('only notifications present in snmpagent_cache are forwarded to a receiver', function () use ($runSelection) {
    list($exit, $stdout, $stderr) = $runSelection('2', array(
        'CACTI-MIB' => array('cactiNotifyDeviceDown' => 1),
        'EVIL-MIB'  => array('cactiNotifyDeviceRecovering' => 1),
    ));

    expect($exit)->toBe(0, $stderr)
        ->and($stdout)->toContain('SQL:INSERT:7|cactiNotifyDeviceDown|CACTI-MIB')
        ->and(substr_count($stdout, 'SQL:'))->toBe(1)
        ->and($stdout)->not->toContain('EVIL-MIB')
        ->and($stdout)->toContain('LOG:WEBUI:WARNING: Rejected unknown SNMP notification selection for receiver 7')
        ->and($stdout)->toContain('HEADER:Location: managers.php?action=edit&id=7&tab=notifications&header=false');
});

test('disabling forwarding deletes only cached notification pairs', function () use ($runSelection) {
    list($exit, $stdout, $stderr) = $runSelection('1', array(
        'CACTI-MIB' => array('cactiNotifyDeviceRecovering' => 1, 'x\' OR 1=1' => 1),
    ));

    expect($exit)->toBe(0, $stderr)
        ->and($stdout)->toContain('SQL:DELETE:7|CACTI-MIB|cactiNotifyDeviceRecovering')
        ->and(substr_count($stdout, 'SQL:'))->toBe(1);
});

test('a selection larger than the notification cache is refused without writes', function () use ($runSelection) {
    $names = array();

    for ($i = 0; $i < 500; $i++) {
        $names['cactiNotifyDeviceDown' . $i] = 1;
    }

    $names['cactiNotifyDeviceDown'] = 1;

    list($exit, $stdout, $stderr) = $runSelection('2', array('CACTI-MIB' => $names));

    expect($exit)->toBe(0, $stderr)
        ->and($stdout)->not->toContain('SQL:')
        ->and($stdout)->toContain('LOG:WEBUI:WARNING: Rejected notification receiver selection with more than 2 entries')
        ->and(substr_count($stdout, 'LOG:'))->toBeLessThan(4);
});

test('a selection that is not an array writes nothing', function () use ($runSelection) {
    list($exit, $stdout, $stderr) = $runSelection('2', 'CACTI-MIB');

    expect($exit)->toBe(0, $stderr)
        ->and($stdout)->not->toContain('SQL:');
});
