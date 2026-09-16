<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

namespace RrdResizeLeaseTest;

require_once dirname(__DIR__, 4) . '/lib/rrd_maintenance.php';
require_once dirname(__DIR__, 3) . '/Helpers/PhpSource.php';
$source = file_get_contents(dirname(__DIR__, 4) . '/lib/rrd.php');
foreach (array('rrd_with_pipe', 'rrdtool_tune') as $name) {
    eval('namespace ' . __NAMESPACE__ . ';' . \test_php_function_source($source, $name));
}
function read_config_option($key)
{
    return $key === 'path_rrdtool' ? 'rrdtool' : ($GLOBALS['resize_remote'] ?? 0);
}
function cacti_log(...$args) {}
function cacti_sizeof($value)
{
    return is_array($value) ? count($value) : 0;
}
function html_end_box() {}
function rrd_init($output, $exclusive, $acknowledged)
{
    expect($exclusive)->toBeTrue()->and($acknowledged)->toBeTrue();
    return \rrd_maintenance_acquire(true);
}
function rrd_close($pipe)
{
    \rrd_maintenance_release($pipe);
}
function rrdtool_execute($command, $output, $flag, $pipe = false)
{
    expect(is_resource($pipe))->toBeTrue();
    if (++$GLOBALS['resize_commands'] === 2) {
        return false;
    }
    file_put_contents(getcwd() . '/resize.rrd', 'replacement');
    return true;
}
function rename($from, $to)
{
    $writer = \rrd_maintenance_acquire(false, false, 0);
    if (is_resource($writer)) {
        \rrd_maintenance_release($writer);
    }
    expect($writer)->toBeFalse();
    $GLOBALS['resize_renames']++;
    return \rename($from, $to);
}

test('resize holds the exclusive lease through replacement and stops after a failed command', function () {
    foreach (array('CACTI_CLI' => true, 'RRDTOOL_OUTPUT_BOOLEAN' => 4) as $key => $value) {
        if (!defined($key)) {
            define($key, $value);
        }
    }
    $directory = sys_get_temp_dir() . '/resize-lease-' . bin2hex(random_bytes(8));
    mkdir($directory, 0700);
    file_put_contents($directory . '/live.rrd', 'original');
    $saved = $GLOBALS['config'] ?? null;
    $cwd = getcwd();
    $GLOBALS['config'] = array('cacti_server_os' => 'unix', 'rra_path' => $directory);
    $GLOBALS['resize_commands'] = $GLOBALS['resize_renames'] = 0;
    chdir($directory);
    try {
        expect(rrdtool_tune($directory . '/live.rrd', array('resize' => array('first', 'second')), false))->toBeFalse()
            ->and($GLOBALS['resize_renames'])->toBe(1)
            ->and(file_get_contents($directory . '/live.rrd'))->toBe('replacement');
        $writer = \rrd_maintenance_acquire(false, false, 0);
        expect(is_resource($writer))->toBeTrue();
        \rrd_maintenance_release($writer);
    } finally {
        chdir($cwd);
        $GLOBALS['config'] = $saved;
        unlink($directory . '/live.rrd');
        rmdir($directory);
        unset($GLOBALS['resize_commands'], $GLOBALS['resize_renames']);
    }
});

test('remote resize fails before sending commands or renaming local files', function () {
    $GLOBALS['resize_remote'] = 1;
    $GLOBALS['resize_commands'] = $GLOBALS['resize_renames'] = 0;
    try {
        expect(rrdtool_tune('/remote/live.rrd', array('resize' => array('first')), false))->toBeFalse()
            ->and($GLOBALS['resize_commands'])->toBe(0)->and($GLOBALS['resize_renames'])->toBe(0);
    } finally {
        unset($GLOBALS['resize_remote'], $GLOBALS['resize_commands'], $GLOBALS['resize_renames']);
    }
});
