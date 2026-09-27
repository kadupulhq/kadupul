<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

require_once __DIR__ . '/include/global.php';
require_once __DIR__ . '/lib/rrd.php';

if (!rrdtool_uses_proxy()) {
    http_response_code(500);
    echo json_encode(array('error' => 'Kadupul is not configured to use the RRDtool proxy'));
    exit;
}

$name = 'proxy-e2e-' . bin2hex(random_bytes(8)) . '.rrd';
$now = time();
$start = $now - 600;
$results = array();
$commands = array(
    array('create', $name, '--start', (string) $start, '--step', '60', 'DS:value:GAUGE:120:0:U', 'RRA:AVERAGE:0.5:1:20'),
    array('update', $name, ($now - 180) . ':1', ($now - 120) . ':2', ($now - 60) . ':3', $now . ':4'),
    array('info', $name),
    array('last', $name),
);

foreach ($commands as $command) {
    $result = rrdtool_execute($command, false, RRDTOOL_OUTPUT_STDOUT);
    if ($result === false || $result === null) {
        http_response_code(502);
        echo json_encode(array(
            'error' => 'RRDtool proxy command failed',
            'command' => $command[0],
            'cacti_log' => file_exists($config['path_cactilog']) ? basename($config['path_cactilog']) : null,
        ));
        exit;
    }
    $results[$command[0]] = $result;
}

if (!str_contains((string) $results['info'], 'ds[value].type') || (int) $results['last'] !== $now) {
    http_response_code(502);
    echo json_encode(array('error' => 'RRDtool proxy returned unexpected RRD data', 'results' => $results));
    exit;
}

echo json_encode(array(
    'ok' => true,
    'transport' => 'rrdproxy',
    'commands' => array_keys($results),
    'last_update' => (int) $results['last'],
    'rrd' => $name,
));
