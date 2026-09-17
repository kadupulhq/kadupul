<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

test('production Boost archive consumer deletes only after acknowledgement and retains failed assignments', function ($mode) {
    $root = dirname(__DIR__, 4);
    $dir = sys_get_temp_dir() . '/boost-archive-' . bin2hex(random_bytes(8));
    mkdir($dir, 0700);
    mkdir($dir . '/include', 0700);
    mkdir($dir . '/lib', 0700);
    foreach (array('poller', 'boost', 'dsstats', 'rrdcheck', 'rrd') as $library) {
        file_put_contents($dir . '/lib/' . $library . '.php', '<?php');
    }
    copy($root . '/poller_boost.php', $dir . '/poller_boost.php');
    $coverage = $this->getTestResultObject()->getCodeCoverage();
    $bootstrap = '<?php ';
    if ($coverage !== null) {
        $bootstrap .= 'define("RRD_TEST_COVERAGE_DIRECTORY",' . var_export($dir, true) . ');' .
            'define("RRD_TEST_CLI_COVERAGE_COPY",' . var_export($dir . '/poller_boost.php', true) . ');' .
            'define("RRD_TEST_CLI_COVERAGE_SOURCE",' . var_export($root . '/poller_boost.php', true) . ');' .
            'require ' . var_export($root . '/tests/Fixtures/rrd-process-coverage.php', true) . ';';
    }
    $bootstrap .= 'require ' . var_export($root . '/tests/Fixtures/boost-archive-bootstrap.php', true) . ';';
    file_put_contents($dir . '/include/cli_check.php', $bootstrap);
    try {
        $process = proc_open(
            array(PHP_BINARY, '-d', 'pcov.directory=/', '-d', 'pcov.exclude=~/(include/vendor|tests)/~', $dir . '/poller_boost.php', '--help'),
            array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')),
            $pipes,
            null,
            array_merge(getenv(), array('BOOST_FIXTURE' => $dir, 'BOOST_MODE' => $mode))
        );
        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        expect(proc_close($process))->toBe(0, $error . $output)->and($error)->toBe('');
        $result = json_decode(file_get_contents($dir . '/result.json'), true, 512, JSON_THROW_ON_ERROR);
        expect($result['result'])->toBe($mode === 'success' ? 2 : false)
            ->and($result['handler_restored'])->toBeTrue();
        $deletes = array_values(array_filter($result['writes'], fn($write) => str_starts_with($write[0], 'DELETE')));
        $count = array('archive-failure' => 0, 'next-id-failure' => 0, 'split-failure' => 0,
            'last-failure' => 0, 'delete-failure' => 1, 'assignment-failure' => 3, 'success' => 3)[$mode];
        expect($deletes)->toHaveCount($count)->and($result['updates'])->toHaveCount($mode === 'archive-failure' ? 0 : 1);
        foreach (array_slice($deletes, 0, 2) as $index => $delete) {
            expect($delete[0])->toBe('DELETE FROM poller_output_boost_arch_fixture WHERE local_data_id = ? AND rrd_name = ? AND time = FROM_UNIXTIME(?) AND CAST(CONVERT(output USING utf8mb4) AS BINARY) = CAST(CONVERT(? USING utf8mb4) AS BINARY)');
            expect($delete[1])->toBe(array(42, 'value', $index === 0 ? '1699999800' : '1699999860', $index === 0 ? '21' : '22'));
        }
        if ($count === 3) {
            expect($deletes[2][0])->toContain('DELETE FROM poller_output_boost_local_data_ids')
                ->and($deletes[2][1])->toBe(array(43, 2));
        }
        if ($coverage !== null) {
            $reports = glob($dir . '/*.coverage');
            expect($reports)->toHaveCount(1);
            $coverage->merge(unserialize(file_get_contents($reports[0])));
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
})->with(array('archive-failure', 'next-id-failure', 'split-failure', 'last-failure', 'delete-failure', 'assignment-failure', 'success'));
