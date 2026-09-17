<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

test('production on-demand Boost deletes only acknowledged sample tuples and preserves caller resources', function ($mode, $owned) {
    $root = dirname(__DIR__, 4);
    $directory = sys_get_temp_dir() . '/boost-acknowledged-' . bin2hex(random_bytes(8));
    mkdir($directory, 0700);
    file_put_contents($directory . '/rrd.php', '<?php');
    // RRDtool acknowledgement is the injected boundary; the queue consumer is production code.
    file_put_contents($directory . '/sample.rrd', 'fixture exists');
    $coverage = $this->getTestResultObject()->getCodeCoverage();
    try {
        $process = proc_open(
            array(PHP_BINARY, '-d', 'display_errors=stderr', '-d', 'pcov.directory=' . $root,
                '-d', 'pcov.exclude=~/(include/vendor|tests)/~', $root . '/tests/Fixtures/boost-on-demand-native.php',
                $root, $directory, $mode, $owned ? '1' : '0', $coverage !== null ? '1' : '0'),
            array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')),
            $pipes
        );
        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        expect(proc_close($process))->toBe(0, $error)->and($error)->toBe('');
        $observed = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
        expect($observed['result'])->toBe($mode === 'success' ? 2 : -1)
            ->and($observed['opened'])->toBe($owned ? 1 : 0)
            ->and($observed['closed'])->toBe($owned ? 1 : 0)
            ->and($observed['borrowed_open'])->toBeTrue()
            ->and($observed['handler_restored'])->toBeTrue()
            ->and($observed['reporting_restored'])->toBeTrue();
        $expectedDeletes = array('first-ack' => 0, 'last-ack' => 0, 'delete-first' => 1, 'delete-later' => 3, 'success' => 4)[$mode];
        expect($observed['deletes'])->toHaveCount($expectedDeletes)->and($observed['updates'])->toHaveCount(1);
        foreach ($observed['deletes'] as $index => $delete) {
            $table = $index < 2 ? 'poller_output_boost' : 'poller_output_boost_arch_fixture';
            expect($delete[0])->toBe("DELETE FROM $table WHERE local_data_id = ? AND rrd_name = ? AND time = FROM_UNIXTIME(?) AND CAST(CONVERT(output USING utf8mb4) AS BINARY) = CAST(CONVERT(? USING utf8mb4) AS BINARY)");
            expect($delete[1])->toBe($index % 2 === 0 ? array(42, 'value', 1699999800, '21') : array(42, 'value', 1699999860, '22'));
        }
        expect($observed['updates'][0])->toContain('--template value')->toContain('1699999800:21');
        if ($mode !== 'first-ack') {
            expect($observed['updates'][0])->toContain('1699999860:22');
        }
        if ($coverage !== null) {
            $reports = glob($directory . '/*.coverage');
            expect($reports)->toHaveCount(1);
            $coverage->merge(unserialize(file_get_contents($reports[0])));
        }
    } finally {
        foreach (glob($directory . '/*') as $file) {
            unlink($file);
        }
        rmdir($directory);
    }
})->with(array('first-ack', 'last-ack', 'delete-first', 'delete-later', 'success'))->with(array(false, true));
