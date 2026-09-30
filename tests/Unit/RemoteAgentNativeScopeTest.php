<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

test('production remote agent preserves authorized handoffs and rejects other collectors targets', function ($mode, $expected) {
    $root = dirname(__DIR__, 2);
    $dir = sys_get_temp_dir() . '/remote-agent-native-' . bin2hex(random_bytes(8));
    mkdir($dir, 0700);
    mkdir($dir . '/include', 0700);
    mkdir($dir . '/lib', 0700);
    copy($root . '/remote_agent.php', $dir . '/remote_agent.php');
    copy($root . '/graph_image.php', $dir . '/graph_image.php');
    foreach (array('api_device', 'api_data_source', 'data_query', 'api_graph', 'api_tree', 'html_form_template', 'ping', 'poller', 'rrd', 'snmp', 'sort', 'template', 'utility') as $library) {
        file_put_contents($dir . '/lib/' . $library . '.php', '<?php');
    }
    file_put_contents($dir . '/lib/remote_agent_auth.php', '<?php require ' . var_export($root . '/lib/remote_agent_auth.php', true) . ';');
    $coverage = $this->getTestResultObject()->getCodeCoverage();
    $bootstrap = '<?php ';
    if ($coverage !== null) {
        foreach (array('RRD_TEST_COVERAGE_DIRECTORY' => $dir, 'RRD_TEST_CLI_COVERAGE_COPY' => $dir . '/remote_agent.php', 'RRD_TEST_CLI_COVERAGE_SOURCE' => $root . '/remote_agent.php') as $name => $value) {
            $bootstrap .= 'define(' . var_export($name, true) . ',' . var_export($value, true) . ');';
        }
        $bootstrap .= 'require ' . var_export($root . '/tests/Fixtures/rrd-process-coverage.php', true) . ';';
    }
    $bootstrap .= 'require ' . var_export($root . '/tests/Fixtures/remote-agent-native-bootstrap.php', true) . ';';
    file_put_contents($dir . '/include/global.php', $bootstrap);
    $imageBootstrap = str_replace(array($dir . '/remote_agent.php', $root . '/remote_agent.php'), array($dir . '/graph_image.php', $root . '/graph_image.php'), $bootstrap);
    file_put_contents($dir . '/include/auth.php', $imageBootstrap);
    try {
        $script = $mode === 'graph-image-collector' ? 'graph_image.php' : 'remote_agent.php';
        $process = proc_open(
            array(PHP_BINARY, '-d', 'auto_prepend_file=', '-d', 'display_errors=stderr', '-d', 'pcov.directory=/', '-d', 'pcov.exclude=~/(include/vendor|tests)/~', $dir . '/' . $script),
            array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')),
            $pipes,
            $dir,
            array_merge(getenv(), array('REMOTE_AGENT_TEST_MODE' => $mode, 'REMOTE_AGENT_TEST_DIRECTORY' => $dir))
        );
        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $this->assertSame(0, proc_close($process), $error);
        expect($error)->toBe('');
        list($body, $json) = explode("\nRESULT:", $output);
        $result = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        expect($body)->toBe($expected);
        if ($expected === 'GRAPH IMAGE') {
            expect($result['calls'])->toContain(array('permission', $mode === 'graph-hostless' ? 21 : 20, 7))
                ->and($result['calls'])->toContain(array('render', $mode === 'graph-hostless' ? 21 : 20, 7));
        } elseif ($expected === 'GRAPH ACCESS DENIED' || str_contains($mode, 'denied') || $mode === 'unauthorized') {
            expect(array_filter($result['calls'], static function ($call) {
                return $call[0] !== 'permission';
            }))->toBe(array());
        }
        if (in_array($mode, array('graph-allowed', 'graph-bounds'), true)) {
            $options = array('disable_cache' => true, 'graph_theme' => 'modern', 'graphv' => true);
            if ($mode === 'graph-allowed') {
                $options = array_merge(array('graph_start' => 1700000000, 'graph_end' => 1700003600, 'graph_height' => 120, 'graph_width' => 240, 'graph_nolegend' => 'true', 'print_source' => '1'), $options);
            }
            expect($result['graph_options'])->toBe($options);
        }
        if (in_array($mode, array('ping-main', 'ping-collector'), true)) {
            expect($result['calls'])->toBe(array(array('ping', $mode === 'ping-main' ? 11 : 10)));
        }
        if (in_array($mode, array('query-main', 'query-collector'), true)) {
            expect($result['calls'])->toBe(array(array('query', $mode === 'query-main' ? 11 : 10, 5)));
        }
        if (str_starts_with($mode, 'discover-') && !str_contains($mode, 'denied')) {
            expect($result['calls'])->toHaveCount(1)
                ->and($result['calls'][0][0])->toBe('discover')
                ->and($result['calls'][0][2])->toContain("--poller='" . (str_contains($mode, 'collector') ? 2 : 1) . "'");
        }
        if (in_array($mode, array('snmp-main', 'walk-main', 'poll-main', 'snmp-failed', 'walk-failed'), true)) {
            expect($result['calls'][0])->toBe(array('session', array('fixture-host', 'public', 2, '', '', '', '', '', '', '', 161, 500, 2, 10)));
            if (str_contains($mode, 'failed')) {
                expect($result['calls'])->toHaveCount(1);
            } else {
                expect($result['calls'][1])->toBe(array($mode === 'walk-main' ? 'walk' : 'get', '1.3.6.1'))
                    ->and($result['calls'][2])->toBe(array('close'));
            }
        }
        if ($coverage !== null) {
            $reports = glob($dir . '/*.coverage');
            expect($reports)->toHaveCount($mode === 'graph-image-collector' ? 2 : 1);
            foreach ($reports as $report) {
                $coverage->merge(unserialize(file_get_contents($report)));
            }
        }
    } finally {
        foreach (array('/include', '/lib', '') as $suffix) {
            foreach (glob($dir . $suffix . '/*') as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
            rmdir($dir . $suffix);
        }
    }
})->with(array(
    array('graph-image-collector', 'GRAPH IMAGE'),
    array('graph-allowed', 'GRAPH IMAGE'), array('graph-bounds', 'GRAPH IMAGE'), array('graph-collector', 'GRAPH IMAGE'),
    array('graph-empty', 'GRAPH IMAGE'), array('graph-hostless', 'GRAPH IMAGE'),
    array('graph-hostless-data', 'GRAPH IMAGE'), array('graph-mixed', 'GRAPH ACCESS DENIED'),
    array('graph-orphan', 'GRAPH ACCESS DENIED'), array('graph-missing', 'GRAPH ACCESS DENIED'),
    array('graph-disabled', 'GRAPH ACCESS DENIED'), array('graph-denied', 'GRAPH ACCESS DENIED'),
    array('graph-no-permission', 'GRAPH ACCESS DENIED'),
    array('graph-no-user', 'GRAPH ACCESS DENIED'), array('ping-main', ''), array('ping-collector', ''),
    array('query-main', ''), array('query-collector', ''), array('ping-denied', 'U'),
    array('query-denied', ''), array('snmp-denied', 'U'), array('poll-denied', '[]'),
    array('walk-denied', 'U'),
    array('snmp-main', '42'), array('snmp-failed', 'U'), array('snmp-invalid', 'U'),
    array('walk-main', '[{"oid":"1.3.6.1","value":"42"}]'), array('walk-failed', 'U'), array('walk-invalid', 'U'),
    array('poll-main', '[{"value":"42","rrd_name":"traffic","local_data_id":40}]'),
    array('discover-main', ''), array('discover-collector', ''), array('discover-denied', ''),
    array('discover-main-broker-all-collector', ''), array('discover-all-denied', ''),
    array('unauthorized', 'FATAL: Client authorization failed.  You are not authorized to use this service')
));
