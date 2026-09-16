<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

namespace RrdProxyAcknowledgement;

require_once dirname(__DIR__, 3) . '/Helpers/PhpSource.php';
eval('namespace ' . __NAMESPACE__ . ';' . \test_php_function_source(file_get_contents(dirname(__DIR__, 4) . '/lib/rrd.php'), '__rrd_proxy_execute'));
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
    foreach (array('RRDTOOL_OUTPUT_NULL' => 0,'RRDTOOL_OUTPUT_STDOUT' => 1,'RRDTOOL_OUTPUT_STDERR' => 2,'RRDTOOL_OUTPUT_GRAPH_DATA' => 3,'RRDTOOL_OUTPUT_BOOLEAN' => 4,'RRDTOOL_OUTPUT_RETURN_STDERR' => 5,'POLLER_VERBOSITY_LOW' => 2,'POLLER_VERBOSITY_DEBUG' => 5) as $name => $value) {
        if (!defined($name)) {
            define($name, $value);
        }
    }
    $GLOBALS['config'] = array('rra_path' => '/fixture');
    $GLOBALS['proxy_reply'] = $response === false ? false : $response . "_EOP_\r\n_EOT_\r\n";
    expect(__rrd_proxy_execute('update /fixture/test.rrd 100:1', false, RRDTOOL_OUTPUT_BOOLEAN, array(true,'test-public')))->toBe($expected);
})->with(array(array("OK u:0.01 s:0.02 r:0.03\n",true),array(false,false),array("ERROR: failed\n",false),array("ERROR: expected OK u:0\n",false),array("ERROR: failed\nOK u:0 s:0 r:0\n",false)));
