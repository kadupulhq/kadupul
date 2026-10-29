<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests\RrdCommandArgumentValidation;

require_once dirname(__DIR__, 3) . '/Helpers/PhpSource.php';

// Namespaced so the loaded functions see them without defining globals that
// other files in the same run rely on.
const RRD_NL = " \\\n";
const RRDTOOL_OUTPUT_STDOUT = 1;
const RRDTOOL_OUTPUT_BOOLEAN = 4;
const CACTI_CLI = true;
const POLLER_VERBOSITY_NONE = 1;
const POLLER_VERBOSITY_HIGH = 4;
const POLLER_VERBOSITY_DEBUG = 5;

/*
 * The functions below run as written from lib/; only process, filesystem and
 * database boundaries are replaced, and every RRDtool command or shell line
 * they would send is recorded.
 */

function rrd_arguments_reset(): void
{
    $GLOBALS['rrd_arguments'] = array('commands' => array(), 'shell' => array(), 'logs' => array());
    $GLOBALS['config'] = array('cacti_server_os' => 'unix', 'rra_path' => sys_get_temp_dir());
    $GLOBALS['debug'] = false;
}

function rrd_arguments(): array
{
    return $GLOBALS['rrd_arguments'];
}

function cacti_log($message, ...$arguments)
{
    $GLOBALS['rrd_arguments']['logs'][] = $message;
}

function rrdtool_execute($command, ...$arguments)
{
    $GLOBALS['rrd_arguments']['commands'][] = $command;

    return true;
}

function exec($command, &$output = null, &$code = null)
{
    $GLOBALS['rrd_arguments']['shell'][] = $command;

    return '';
}

function file_exists($path)
{
    return true;
}

function cacti_escapeshellarg($value)
{
    return escapeshellarg($value);
}

function cacti_sizeof($value)
{
    return is_array($value) ? count($value) : 0;
}

function rrdtool_uses_proxy()
{
    return false;
}

// These cases use paths that do not exist; RrdPathContainmentTest covers the check against a real directory.
function rrd_check_path($path, $rra_base = null, $logopt = null)
{
    return true;
}

function rrdtool_command_path($path)
{
    return "'" . $path . "'";
}

function rrdtool_command_argument($argument)
{
    return "'" . $argument . "'";
}

function get_rrdtool_version()
{
    return '1.8';
}

function cacti_version_compare($left, $right, $operator)
{
    return version_compare($left, $right, $operator);
}

function &rrd_acknowledged_pipes()
{
    static $pipes = array();

    return $pipes;
}

function read_config_option($name, $force = false)
{
    return '/usr/bin/rrdtool';
}

function db_fetch_assoc_prepared($sql, $params = array())
{
    return array(array('data_source_name' => 'traffic_in'));
}

function array_rekey($rows, $key, $value)
{
    return array_column($rows, $value, $key);
}

function rrdtool_last_rejection()
{
    return null;
}

function rrdtool_rejection_is_permanent($reason)
{
    return false;
}

$root  = dirname(__DIR__, 4);
$files = array(
    'lib/rrd.php'      => array('rrd_path_is_contained', 'rrd_resolve_missing_path', 'rrdtool_ds_name_is_valid', 'rrdtool_ds_template_is_valid', 'rrdtool_create_minimum', 'rrdtool_create_maximum', 'rrdtool_create_ds', 'rrdtool_function_update', 'rrdtool_tune'),
    'lib/boost.php'    => array('boost_rrdtool_function_update'),
    'lib/poller.php'   => array('exec_background', 'file_escaped'),
    'data_sources.php' => array('data_source_path_is_allowed'),
);

foreach ($files as $file => $functions) {
    $source = file_get_contents($root . '/' . $file);
    if ($source === false) {
        throw new \RuntimeException('Unable to read ' . $file);
    }

    foreach ($functions as $function) {
        // The validators are new; load them when present so a run against the
        // unhardened sinks reaches the assertions below.
        if (str_starts_with($function, 'rrdtool_ds_') && !str_contains($source, 'function ' . $function . '(')) {
            continue;
        }

        eval('namespace ' . __NAMESPACE__ . ';' . \test_php_function_source($source, $function)); // nosemgrep: php.lang.security.eval-use.eval-use
    }
}

$saved_globals = array();

beforeEach(function () use (&$saved_globals) {
    foreach (array('config', 'debug', 'rrd_arguments') as $name) {
        $saved_globals[$name] = array_key_exists($name, $GLOBALS) ? array($GLOBALS[$name]) : null;
    }
});

// Restore what other files in the same run may read.
afterEach(function () use (&$saved_globals) {
    foreach ($saved_globals as $name => $value) {
        if ($value === null) {
            unset($GLOBALS[$name]);
        } else {
            $GLOBALS[$name] = $value[0];
        }
    }
});

test('data source names and update templates follow RRDtool DS name syntax', function () {
    expect(rrdtool_ds_name_is_valid('traffic_in'))->toBeTrue()
        ->and(rrdtool_ds_name_is_valid('a-b_9'))->toBeTrue()
        ->and(rrdtool_ds_name_is_valid(str_repeat('a', 19)))->toBeTrue()
        ->and(rrdtool_ds_name_is_valid(str_repeat('a', 20)))->toBeFalse()
        ->and(rrdtool_ds_name_is_valid("traffic_in\n"))->toBeFalse()
        ->and(rrdtool_ds_name_is_valid('a b'))->toBeFalse()
        ->and(rrdtool_ds_template_is_valid('traffic_in:traffic_out'))->toBeTrue()
        ->and(rrdtool_ds_template_is_valid('traffic_in::traffic_out'))->toBeFalse()
        ->and(rrdtool_ds_template_is_valid(''))->toBeFalse()
        ->and(rrdtool_ds_template_is_valid("traffic_in\ninfo /x"))->toBeFalse();
});

test('a create command keeps a valid data source name and refuses one RRDtool cannot parse', function () {
    rrd_arguments_reset();
    $row = array('rrd_minimum' => '0', 'rrd_maximum' => 'U', 'rrd_heartbeat' => '600');

    expect(rrdtool_create_ds('traffic_in', 'COUNTER', $row, 7, 'POLLER'))->toBe('DS:traffic_in:COUNTER:600:0:U' . RRD_NL)
        ->and(rrdtool_create_ds("x:GAUGE:600:U:U\nRRA:AVERAGE:0.5:1:1", 'COUNTER', $row, 7, 'POLLER'))->toBeFalse()
        ->and(rrd_arguments()['logs'])->toHaveCount(1);
});

test('the poller does not send an update template that falls back to an invalid one', function () {
    rrd_arguments_reset();
    $cache = array('/rra/a.rrd' => array(
        'local_data_id'    => 7,
        'data_template_id' => 3,
        'template'         => "traffic_in\ninfo /rra/b.rrd",
        'times'            => array(1700000000 => array('traffic_in' => '5')),
    ));

    expect(rrdtool_function_update($cache, false, $completed))->toBeFalse()
        ->and(rrd_arguments()['commands'])->toBe(array())
        ->and($completed)->toBe(array('/rra/a.rrd' => array(1700000000 => false)));
});

test('the poller still sends a valid update', function () {
    rrd_arguments_reset();
    $cache = array('/rra/a.rrd' => array(
        'local_data_id'    => 7,
        'data_template_id' => 0,
        'times'            => array(1700000000 => array('traffic_out' => '5')),
    ));

    expect(rrdtool_function_update($cache, false))->toBe(1)
        ->and(rrd_arguments()['commands'])->toBe(array("update '/rra/a.rrd' --template traffic_out 1700000000:5"));
});

test('boost refuses an update template or value set that would start another command', function (string $template, string $values) {
    rrd_arguments_reset();
    $pipe = false;

    expect(boost_rrdtool_function_update(7, '/rra/a.rrd', $template, $values, $pipe))->toStartWith('ERROR:')
        ->and(rrd_arguments()['commands'])->toBe(array());
})->with(array(
    'template' => array("traffic_in\ninfo /rra/b.rrd", '1700000000:5'),
    'values'   => array('traffic_in', "1700000000:5\ninfo /rra/b.rrd"),
));

test('boost still sends a valid update', function () {
    rrd_arguments_reset();
    $pipe   = false;
    $values = '1700000000:5:6';

    expect(boost_rrdtool_function_update(7, '/rra/a.rrd', 'traffic_in:traffic_out', $values, $pipe))->toBe('OK')
        ->and(rrd_arguments()['commands'])->toBe(array("update '/rra/a.rrd' --skip-past-updates --template traffic_in:traffic_out 1700000000:5:6"));
});

test('tune lines with a control character are not sent to RRDtool', function () {
    rrd_arguments_reset();

    rrdtool_tune('/rra/a.rrd', array('tune' => array("/rra/a.rrd --maximum traffic_in:10\ninfo /rra/b.rrd", '/rra/a.rrd --maximum traffic_out:10')), false);

    expect(rrd_arguments()['commands'])->toBe(array('tune /rra/a.rrd --maximum traffic_out:10'));
});

test('a background process is started through a quoted executable path', function () {
    rrd_arguments_reset();

    exec_background('/opt/php 8/bin/php;touch x', '-q /srv/poller.php');

    expect(rrd_arguments()['shell'])->toBe(array("'/opt/php 8/bin/php;touch x' -q /srv/poller.php > /dev/null 2>&1 &"));
});

test('a data source path with a line break is not accepted', function () {
    rrd_arguments_reset();

    expect(data_source_path_is_allowed('<path_rra>/a.rrd'))->toBeTrue()
        ->and(data_source_path_is_allowed("<path_rra>/a.rrd\ninfo /x.rrd"))->toBeFalse()
        ->and(data_source_path_is_allowed("<path_rra>/a\r.rrd"))->toBeFalse();
});

test('spike removal passes each RRD path as one shell argument', function () {
    $source = file_get_contents(dirname(__DIR__, 4) . '/poller_spikekill.php');

    expect($source)->toContain("' --rrdfile=' . cacti_escapeshellarg(\$f)")
        ->and($source)->not->toContain("' --rrdfile=' . \$f");
});
