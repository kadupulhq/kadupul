<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

namespace RrdProxyAcknowledgement;

require_once dirname(__DIR__, 3) . '/Helpers/PhpSource.php';
eval('namespace ' . __NAMESPACE__ . ';' . \test_php_function_source(file_get_contents(dirname(__DIR__, 4) . '/lib/rrd.php'), '__rrd_proxy_execute'));
eval('namespace ' . __NAMESPACE__ . ';' . \test_php_function_source(file_get_contents(dirname(__DIR__, 4) . '/lib/rrd.php'), 'rrdtool_last_rejection'));
eval('namespace ' . __NAMESPACE__ . ';' . \test_php_function_source(file_get_contents(dirname(__DIR__, 4) . '/lib/rrd.php'), 'rrdtool_proxy_write'));
eval('namespace ' . __NAMESPACE__ . ';' . \test_php_function_source(file_get_contents(dirname(__DIR__, 4) . '/lib/rrd.php'), 'rrdtool_rejection_is_permanent'));
eval('namespace ' . __NAMESPACE__ . ';' . \test_php_function_source(file_get_contents(dirname(__DIR__, 4) . '/lib/rrd.php'), 'rrdtool_proxy_relative_paths'));
function cacti_log(...$args) {}
function read_config_option($key)
{
    return '';
}
function encrypt($data, ...$args)
{
    return $data;
}
function decrypt($data)
{
    return $data;
}
function socket_write($socket, $data)
{
    return strlen($data);
}
function socket_read(...$args)
{
    $value = $GLOBALS['proxy_reply'];
    $GLOBALS['proxy_reply'] = false;
    return $value;
}
test('proxy acknowledgements reject missing responses and error responses even when they contain OK', function ($response, $expected) {
    foreach (array('PHP_BINARY_READ' => 2, 'RRDTOOL_OUTPUT_NULL' => 0,'RRDTOOL_OUTPUT_STDOUT' => 1,'RRDTOOL_OUTPUT_STDERR' => 2,'RRDTOOL_OUTPUT_GRAPH_DATA' => 3,'RRDTOOL_OUTPUT_BOOLEAN' => 4,'RRDTOOL_OUTPUT_RETURN_STDERR' => 5,'POLLER_VERBOSITY_LOW' => 2,'POLLER_VERBOSITY_DEBUG' => 5) as $name => $value) {
        if (!defined($name)) {
            define($name, $value);
        }
    }
    $rejection = & rrdtool_last_rejection();
    $rejection = null;
    $GLOBALS['config'] = array('rra_path' => '/fixture');
    $GLOBALS['proxy_reply'] = $response === false ? false : $response . "_EOP_\r\n_EOT_\r\n";
    expect(__rrd_proxy_execute('update /fixture/test.rrd 100:1', false, RRDTOOL_OUTPUT_BOOLEAN, array(true,'test-public')))->toBe($expected);
    if (is_string($response) && preg_match('/^ERROR:([^\r\n]*)/m', $response, $error)) {
        expect($rejection)->toBe(trim($error[1]));
        if (strpos($response, 'unknown DS name') !== false || strpos($response, 'found extra data') !== false) {
            expect(rrdtool_rejection_is_permanent($rejection))->toBeFalse();
        }
        if (strpos($response, 'Permission denied') !== false) {
            expect(rrdtool_rejection_is_permanent($rejection))->toBeFalse();
        }
    } else {
        expect($rejection)->toBeNull();
    }
})->with(array(array("OK\n",true),array("OK\r\n",true),array("ERROR: failed\nOK\n",false),array("OK u:0.01 s:0.02 r:0.03\n",true),array(false,null),array("invalid OK u:0.00",null),array("ERROR: /fixture/test.rrd: found extra data on update argument: 43\n",false),array("ERROR: failed\n",false),array("ERROR: unknown DS name 'missing'\n",false),array("ERROR: opening file: Permission denied\n",false),array("ERROR: expected OK u:0\n",false),array("ERROR: failed\nOK u:0 s:0 r:0\n",false)));
