<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

/** Runs the production archive consumer in a child and returns its recorded effects. */
function boost_archive_run($coverage, $mode)
{
    $root = dirname(__DIR__, 4);
    $dir = sys_get_temp_dir() . '/boost-archive-' . bin2hex(random_bytes(8));
    mkdir($dir, 0700);
    mkdir($dir . '/include', 0700);
    mkdir($dir . '/lib', 0700);
    foreach (array('poller', 'boost', 'dsstats', 'rrdcheck', 'rrd') as $library) {
        file_put_contents($dir . '/lib/' . $library . '.php', '<?php');
    }
    copy($root . '/poller_boost.php', $dir . '/poller_boost.php');
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
        if ($coverage !== null) {
            $reports = glob($dir . '/*.coverage');
            expect($reports)->toHaveCount(1);
            $coverage->merge(unserialize(file_get_contents($reports[0])));
        }
        return $result;
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
}

test('production Boost archive consumer deletes only acknowledged samples and isolates failed data sources', function ($mode) {
    $result = boost_archive_run($this->getTestResultObject()->getCodeCoverage(), $mode);
    $expectedResult = array('archive-failure' => false, 'next-id-failure' => 1, 'split-failure' => 0,
        'last-failure' => 0, 'delete-failure' => false, 'assignment-failure' => false, 'success' => 2)[$mode];
    expect($result['result'])->toBe($expectedResult)
        ->and($result['handler_restored'])->toBeTrue();
    $deletes = array_values(array_filter($result['writes'], fn($write) => str_starts_with($write[0], 'DELETE')));
    $archive = array_values(array_filter($deletes, fn($write) => str_contains($write[0], 'poller_output_boost_arch_')));
    $first = array(42, 'value', '1699999800', '21');
    $second = array($mode === 'next-id-failure' ? 43 : 42, 'value', '1699999860', '22');
    // Samples of a failed data source stay queued; later data sources are still acknowledged.
    $expectedArchive = array('delete-failure' => array($first, $second), 'assignment-failure' => array($first, $second),
        'success' => array($first, $second), 'next-id-failure' => array($second))[$mode] ?? array();
    // Every acknowledged tuple of a table is removed by one statement.
    expect($archive)->toHaveCount($expectedArchive ? 1 : 0)
        ->and($result['updates'])->toHaveCount(array('archive-failure' => 0, 'next-id-failure' => 2)[$mode] ?? 1);
    if ($expectedArchive) {
        expect($archive[0][0])->toBe('DELETE FROM poller_output_boost_arch_fixture WHERE ' . implode(' OR ', array_fill(0, count($expectedArchive), '(local_data_id = ? AND rrd_name = ? AND time = FROM_UNIXTIME(?) AND CAST(CONVERT(output USING utf8mb4) AS BINARY) = CAST(CONVERT(? USING utf8mb4) AS BINARY))')))
            ->and($archive[0][1])->toBe(array_merge(...$expectedArchive));
    }
    if (!in_array($mode, array('archive-failure', 'delete-failure'), true)) {
        expect(end($deletes)[0])->toContain('DELETE FROM poller_output_boost_local_data_ids')
            ->and(end($deletes)[1])->toBe(array(43, 2));
    }
    // Refused samples are handed to the bounded dead-letter move; a lost reply is not.
    expect($result['dead_letters'])->toBe(in_array($mode, array('next-id-failure', 'split-failure'), true)
        ? array(array(42, 'sample-42.rrd', "unknown DS name 'value'", array('poller_output_boost_arch_fixture'))) : array());
    $retained = array_values(array_filter($result['messages'], fn($message) => str_contains($message, 'retained samples')));
    expect($retained)->toBe(in_array($mode, array('next-id-failure', 'split-failure', 'last-failure'), true)
        ? array('WARNING: Boost retained samples for Local Data IDs 42 after RRD update failures.') : array());
})->with(array('archive-failure', 'next-id-failure', 'split-failure', 'last-failure', 'delete-failure', 'assignment-failure', 'success'));

test('unmapped MULTI and invalid Boost outputs are acknowledged without an RRD write', function () {
    $result = boost_archive_run($this->getTestResultObject()->getCodeCoverage(), 'multi');
    $invalid = array_values(array_filter($result['messages'], fn($message) => str_starts_with($message, 'WARNING: Invalid output! MULTI DS')));
    expect($result['result'])->toBe(4)
        ->and($result['updates'])->toBe(array())
        ->and($result['dead_letters'])->toBe(array())
        ->and($invalid)->toHaveCount(2);
    $archive = array_values(array_filter($result['writes'], fn($write) => str_starts_with($write[0], 'DELETE FROM poller_output_boost_arch_')));
    expect($archive)->toHaveCount(1)->and(array_chunk($archive[0][1], 4))->toHaveCount(4);
});
