<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

/** Runs the production archive consumer in a child and returns its recorded effects. */
function boost_archive_run($coverage, $mode, bool $renamed = false, array $environment = array())
{
    $root = dirname(__DIR__, 4);
    $dir = sys_get_temp_dir() . '/boost-archive-' . bin2hex(random_bytes(8));
    mkdir($dir, 0700);
    mkdir($dir . '/include', 0700);
    mkdir($dir . '/lib', 0700);
    foreach (array('poller', 'boost', 'dsstats', 'rrdcheck', 'rrd') as $library) {
        file_put_contents($dir . '/lib/' . $library . '.php', '<?php');
    }
    $entrypoint = $dir . ($renamed ? '/poller.php' : '/poller_boost.php');
    copy($root . '/poller_boost.php', $entrypoint);
    $bootstrap = '<?php ';
    if ($coverage !== null) {
        $bootstrap .= 'define("RRD_TEST_COVERAGE_DIRECTORY",' . var_export($dir, true) . ');' .
            'define("RRD_TEST_CLI_COVERAGE_COPY",' . var_export($entrypoint, true) . ');' .
            'define("RRD_TEST_CLI_COVERAGE_SOURCE",' . var_export($root . '/poller_boost.php', true) . ');' .
            'require ' . var_export($root . '/tests/Fixtures/rrd-process-coverage.php', true) . ';';
    }
    $bootstrap .= 'require ' . var_export($root . '/tests/Fixtures/boost-archive-bootstrap.php', true) . ';';
    file_put_contents($dir . '/include/cli_check.php', $bootstrap);
    try {
        $process = proc_open(
            array(PHP_BINARY, '-d', 'pcov.directory=/', '-d', 'pcov.exclude=~/(include/vendor|tests)/~', $entrypoint, '--help'),
            array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')),
            $pipes,
            null,
            array_merge(getenv(), $environment, array('BOOST_FIXTURE' => $dir, 'BOOST_MODE' => $mode))
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
            $child = unserialize(file_get_contents($reports[0]));
            expect($child->filter()->files())->not->toContain($entrypoint)
                ->and($child->filter()->files())->toContain($root . '/poller_boost.php');
            $coverage->merge($child);
            $result['scratch_directory'] = $dir;
        }
        return $result;
    } finally {
        // The merged report no longer references the copied entrypoint.
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

test('the scheduled Boost writer normalizes scalar and both MULTI sample mappings', function ($sample, $expected, $format) {
    $result = boost_archive_run(
        $this->getTestResultObject()->getCodeCoverage(),
        'finite-sample',
        false,
        array('BOOST_SAMPLE' => $sample, 'BOOST_FORMAT' => $format)
    );

    expect($result['result'])->toBe($format === 'scalar' ? 2 : 1)
        ->and($result['updates'][0][2])->toBe('1699999800:' . $expected . ':7');
    $archive = array_values(array_filter($result['writes'], fn($write) => str_starts_with($write[0], 'DELETE FROM poller_output_boost_arch_')));
    expect($archive)->toHaveCount(1)
        ->and($archive[0][1][3])->toBe($format === 'scalar' ? $sample : $format . ':' . $sample . ' peer:7');
})->with(array(
    array('1e999', 'U'), array('-1e999', 'U'), array('0', '0'),
    array('-1.5', '-1.5'), array('1e3', '1e3'), array('U', 'U'),
))->with(array('scalar', 'cached', 'uncached'));

test('production Boost archive consumer deletes only acknowledged samples and isolates failed data sources', function ($mode) {
    $result = boost_archive_run($this->getTestResultObject()->getCodeCoverage(), $mode);
    $expectedResult = array('archive-failure' => false, 'next-id-failure' => 1, 'split-failure' => 0,
        'last-failure' => 0, 'delete-failure' => false, 'assignment-failure' => false, 'lookup-failure' => 1, 'success' => 2)[$mode];
    expect($result['result'])->toBe($expectedResult)
        ->and($result['handler_restored'])->toBeTrue();
    $deletes = array_values(array_filter($result['writes'], fn($write) => str_starts_with($write[0], 'DELETE')));
    $archive = array_values(array_filter($deletes, fn($write) => str_contains($write[0], 'poller_output_boost_arch_')));
    $first = array(42, 'value', '1699999800', '21');
    $second = array(in_array($mode, array('next-id-failure', 'lookup-failure'), true) ? 43 : 42, 'value', '1699999860', '22');
    // Samples of a failed data source stay queued; later data sources are still acknowledged.
    $expectedArchive = array('delete-failure' => array($first, $second), 'assignment-failure' => array($first, $second),
        'success' => array($first, $second), 'next-id-failure' => array($second), 'lookup-failure' => array($second))[$mode] ?? array();
    // Every acknowledged tuple of a table is removed by one statement.
    expect($archive)->toHaveCount($expectedArchive ? 1 : 0)
        ->and($result['updates'])->toHaveCount(array('archive-failure' => 0, 'next-id-failure' => 2)[$mode] ?? 1);
    if ($mode === 'lookup-failure') {
        expect(array_column($result['updates'], 0))->toBe(array(43));
    }
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
    expect($retained)->toBe(in_array($mode, array('next-id-failure', 'split-failure', 'last-failure', 'lookup-failure'), true)
        ? array('WARNING: Boost retained samples for Local Data IDs 42 after RRD update failures.') : array());
})->with(array('archive-failure', 'next-id-failure', 'split-failure', 'last-failure', 'delete-failure', 'assignment-failure', 'lookup-failure', 'success'));

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


test('a renamed Boost entrypoint reports the original source and removes its scratch directory', function () {
    $coverage = $this->getTestResultObject()->getCodeCoverage();
    $result = boost_archive_run($coverage, 'success', true);
    expect($result['result'])->toBe(2);
    if ($coverage !== null) {
        expect(is_dir($result['scratch_directory']))->toBeFalse();
        expect($coverage->filter()->files())->not->toContain($result['scratch_directory'] . '/poller.php');
    }
});
