<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

test('production graph item editors preserve fixed widths and source associations', function ($script, $mode) {
    $root = dirname(__DIR__, 2);
    $dir = sys_get_temp_dir() . '/graph-item-native-' . bin2hex(random_bytes(8));
    mkdir($dir, 0700);
    mkdir($dir . '/include', 0700);
    mkdir($dir . '/lib', 0700);
    copy($root . '/' . $script, $dir . '/' . $script);
    foreach (array('poller', 'utility', 'api_data_source', 'template') as $name) {
        file_put_contents($dir . '/lib/' . $name . '.php', '<?php');
    }
    file_put_contents($dir . '/lib/graph_item_editor.php', '<?php require_once ' . var_export($root . '/lib/graph_item_editor.php', true) . ';');
    $coverage = $this->getTestResultObject()->getCodeCoverage();
    $bootstrap = '<?php define("GRAPH_ITEM_EDITOR_TEST_COVERAGE", true); ';
    if ($coverage !== null) {
        foreach (array('RRD_TEST_COVERAGE_DIRECTORY' => $dir, 'RRD_TEST_CLI_COVERAGE_COPY' => $dir . '/' . $script, 'RRD_TEST_CLI_COVERAGE_SOURCE' => $root . '/' . $script) as $name => $coverageValue) {
            $bootstrap .= 'define(' . var_export($name, true) . ',' . var_export($coverageValue, true) . ');';
        }
        $bootstrap .= 'require ' . var_export($root . '/tests/Fixtures/rrd-process-coverage.php', true) . ';';
    }
    $bootstrap .= 'require ' . var_export($root . '/tests/Fixtures/graph-item-native-bootstrap.php', true) . ';';
    file_put_contents($dir . '/include/auth.php', $bootstrap);
    try {
        $process = proc_open(array(PHP_BINARY, '-d', 'auto_prepend_file=', '-d', 'display_errors=stderr', '-d', 'pcov.directory=/', $dir . '/' . $script), array(1 => array('pipe','w'), 2 => array('pipe','w')), $pipes, $dir, array_merge(getenv(), array('GRAPH_ITEM_TEST_MODE' => $mode, 'GRAPH_ITEM_TEST_ROOT' => $root)));
        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $this->assertSame(0, proc_close($process), $error);
        expect($error)->toBe('');
        list($body, $json) = explode("\nRESULT:", $output);
        $calls = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        if (str_starts_with($mode, 'invalid-')) {
            expect(array_filter($calls, static fn($call) => $call[0] === 'save'))->toBeEmpty();
            expect(end($calls))->toBe(array('errors', array(substr($mode, 8))));
        } elseif ($mode === 'save' || str_starts_with($mode, 'save-')) {
            $saves = array_values(array_filter($calls, static function ($call) {
                return $call[0] === 'save';
            }));
            $type = $mode === 'save' ? 4 : (int) substr($mode, 5);
            expect($saves)->toHaveCount($type === 10 ? 3 : ($type === 15 ? 4 : 1));
            foreach ($saves as $save) {
                expect($save[1]['line_width'])->toBe($type === 20 ? '2.50' : ($type >= 4 && $type <= 6 ? $type - 3 : 0))
                    ->and($save[1]['task_item_id'])->toBe(6);
            }
            if ($type === 10 || $type === 15) {
                expect(array_column(array_column($saves, 1), 'consolidation_function_id'))->toBe($type === 10 ? array('4', '1', '3') : array('4', '1', '2', '3'));
                expect(end($saves)[1]['hard_return'])->toBe('on');
                expect($saves[0][1]['text_format'])->toBe($script === 'graphs_items.php' && $type === 10 ? 'Cur:' : 'translated:Cur:');
            }
        } elseif ($mode === 'item_edit') {
            $forms = array_values(array_filter($calls, static function ($call) {
                return $call[0] === 'form';
            }));
            expect($forms)->toHaveCount(1)->and($forms[0][1]['fields']['line_width']['value'])->toBe('1');
            expect($body)->toContain("$('#row_line_width').hide();")->and($body)->toContain("$('#row_line_width').show();");
        } elseif (str_starts_with($mode, 'item_move')) {
            expect($calls)->toHaveCount(1);
            $direction = str_starts_with($mode, 'item_moveup') ? 'previous' : 'next';
            if (str_ends_with($mode, '-single')) {
                expect($calls[0][0])->toBe('move-single')->and($calls[0][1])->toBe($direction);
            } else {
                expect($calls[0][0])->toBe('move')->and($calls[0][1][3])->toBe($direction);
            }
        } else {
            expect($body)->toContain('First')->and($body)->toContain('Second')->and($body)->toContain('Third');
        }
        if ($coverage !== null) {
            foreach (glob($dir . '/*.coverage') as $report) {
                $coverage->merge(unserialize(file_get_contents($report)));
            }
        }
    } finally {
        foreach (array('/include','/lib','') as $suffix) {
            foreach (glob($dir . $suffix . '/*') as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            } rmdir($dir . $suffix);
        }
    }
})->with(array(
    array('graphs_items.php', 'invalid-line_width'), array('graph_templates_items.php', 'invalid-line_width'),
    array('graphs_items.php', 'invalid-alpha'), array('graphs_items.php', 'invalid-dashes'), array('graphs_items.php', 'invalid-dash_offset'),
    array('graph_templates_items.php', 'invalid-alpha'), array('graph_templates_items.php', 'invalid-dashes'), array('graph_templates_items.php', 'invalid-dash_offset'),
    array('graph_templates_items.php', 'item_moveup-single'), array('graph_templates_items.php', 'item_movedown-single'),
    array('graphs_items.php', 'save-5'), array('graphs_items.php', 'save-6'), array('graphs_items.php', 'save-20'), array('graphs_items.php', 'save-10'), array('graphs_items.php', 'save-15'),
    array('graph_templates_items.php', 'save-5'), array('graph_templates_items.php', 'save-6'), array('graph_templates_items.php', 'save-20'), array('graph_templates_items.php', 'save-10'), array('graph_templates_items.php', 'save-15'),array('graphs_items.php','save'),array('graphs_items.php','item_edit'),array('graph_templates_items.php','save'),array('graph_templates_items.php','item_edit'),array('graph_templates_items.php','ajax_data_sources'),array('graph_templates_items.php','item_moveup'),array('graph_templates_items.php','item_movedown')));


test('graph item numeric form validation follows the fields used by rendering', function ($script, $value, $shift, $type, $invalid) {
    $root = dirname(__DIR__, 2);
    $directory = sys_get_temp_dir() . '/graph-item-value-' . bin2hex(random_bytes(8));
    mkdir($directory, 0700);
    mkdir($directory . '/include', 0700);
    mkdir($directory . '/lib', 0700);
    foreach (array('poller', 'utility', 'api_data_source', 'template') as $name) {
        file_put_contents($directory . '/lib/' . $name . '.php', '<?php');
    }
    $coverage = $this->getTestResultObject()->getCodeCoverage();
    $bootstrap = '<?php define("GRAPH_ITEM_EDITOR_TEST_COVERAGE", true); ';
    if ($coverage !== null) {
        foreach (array('RRD_TEST_COVERAGE_DIRECTORY' => $directory, 'RRD_TEST_CLI_COVERAGE_COPY' => $directory . '/' . $script, 'RRD_TEST_CLI_COVERAGE_SOURCE' => $root . '/' . $script) as $name => $coverageValue) {
            $bootstrap .= 'define(' . var_export($name, true) . ',' . var_export($coverageValue, true) . ');';
        }
        $bootstrap .= 'require ' . var_export($root . '/tests/Fixtures/rrd-process-coverage.php', true) . ';';
    }
    $bootstrap .= 'require ' . var_export($root . '/tests/Fixtures/graph-item-native-bootstrap.php', true) . ';';
    file_put_contents($directory . '/include/auth.php', $bootstrap);
    file_put_contents($directory . '/lib/graph_item_editor.php', '<?php require_once ' . var_export($root . '/lib/graph_item_editor.php', true) . ';');
    copy($root . '/' . $script, $directory . '/' . $script);
    try {
        $environment = array_replace(getenv(), array('GRAPH_ITEM_TEST_ROOT' => $root, 'GRAPH_ITEM_TEST_MODE' => 'save-' . $type,
            'GRAPH_ITEM_TEST_VALIDATION' => '1', 'GRAPH_ITEM_TEST_PAYLOAD' => json_encode(array('value' => $value, 'shift' => $shift), JSON_THROW_ON_ERROR)));
        $process = proc_open(array(PHP_BINARY, '-d', 'error_reporting=24575', $directory . '/' . $script), array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes, $directory, $environment);
        expect($process)->toBeResource();
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        \PHPUnit\Framework\Assert::assertSame(0, proc_close($process), $stderr . $stdout);
        expect($stderr)->toBe('');
        $calls = json_decode(substr($stdout, strrpos($stdout, 'RESULT:') + 7), true, 512, JSON_THROW_ON_ERROR);
        if ($coverage !== null) {
            $reports = glob($directory . '/*.coverage');
            expect($reports)->toHaveCount(1);
            $coverage->merge(unserialize(file_get_contents($reports[0])));
        }
        $validation = array_values(array_filter($calls, static fn($call) => $call[0] === 'validation'));
        expect($validation)->toHaveCount(1);
        \PHPUnit\Framework\Assert::assertSame($invalid, isset($validation[0][1]['value']), json_encode($calls, JSON_THROW_ON_ERROR));
        $saves = array_values(array_filter($calls, static fn($call) => $call[0] === 'save'));
        if ($invalid) {
            expect($saves)->toBe(array());
        } else {
            expect($saves)->toHaveCount(1)->and($saves[0][1]['value'])->toBe($value);
        }
    } finally {
        foreach (array('/include', '/lib', '') as $suffix) {
            foreach (glob($directory . $suffix . '/*') as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
            rmdir($directory . $suffix);
        }
    }
})->with(function () {
    foreach (array('graphs_items.php', 'graph_templates_items.php') as $script) {
        foreach (array('3600', '-60', '.5', '') as $value) {
            yield array($script, $value, 'on', 4, false);
        }
        foreach (array("1\n", '1:2', '1 2', 'NaN') as $value) {
            yield array($script, $value, 'on', 4, true);
            yield array($script, $value, 'on', 7, true);
            yield array($script, $value, '', 30, true);
        }
        yield array($script, '|query_ifSpeed|', '', 4, false);
        yield array($script, '|query_ifSpeed|', '', 7, false);
        yield array($script, '|query_ifSpeed|', 'on', 7, true);
        yield array($script, '0.5', '', 30, false);
        foreach (array(1, 2, 3) as $type) {
            foreach (array('|query_ifSpeed|', '12:30') as $value) {
                yield array($script, $value, '', $type, false);
            }
        }
    }
});
