<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

test('production on-demand Boost normalizes samples and deletes only acknowledged tuples', function ($mode, $owned, $sample, $expected, $multi) {
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
                $root, $directory, $mode, $owned ? '1' : '0', $coverage !== null ? '1' : '0', $sample, $multi ? 'multi' : 'scalar'),
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
        // One statement per queue table removes every acknowledged tuple.
        $expectedDeletes = array('first-ack' => 0, 'last-ack' => 0, 'delete-first' => 1, 'delete-later' => 2, 'success' => 2)[$mode];
        expect($observed['deletes'])->toHaveCount($expectedDeletes)->and($observed['updates'])->toHaveCount(1);
        $tuple = '(local_data_id = ? AND rrd_name = ? AND time = FROM_UNIXTIME(?) AND CAST(CONVERT(output USING utf8mb4) AS BINARY) = CAST(CONVERT(? USING utf8mb4) AS BINARY))';
        foreach ($observed['deletes'] as $index => $delete) {
            $table = $index === 0 ? 'poller_output_boost' : 'poller_output_boost_arch_fixture';
            expect($delete[0])->toBe("DELETE FROM $table WHERE $tuple OR $tuple");
            expect($delete[1])->toBe(array(42, $multi ? '' : 'value', 1699999800, $multi ? 'value:' . $sample : $sample, 42, 'value', 1699999860, '22'));
        }
        expect($observed['updates'][0])->toStartWith("update '" . $directory . "/sample.rrd' ")->toContain('--template value')->toContain('1699999800:' . $expected);
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
})->with(array('first-ack', 'last-ack', 'delete-first', 'delete-later', 'success'))->with(array(false, true))->with(array(
    'scalar baseline' => array('21', '21', false),
    'scalar overflow' => array('1e999', 'U', false),
    'scalar negative overflow' => array('-1e999', 'U', false),
    'scalar zero' => array('0', '0', false),
    'scalar negative fraction' => array('-1.5', '-1.5', false),
    'scalar finite exponent' => array('1e3', '1e3', false),
    'scalar unknown' => array('U', 'U', false),
    'multi overflow' => array('1e999', 'U', true),
    'multi negative overflow' => array('-1e999', 'U', true),
    'multi zero' => array('0', '0', true),
    'multi negative fraction' => array('-1.5', '-1.5', true),
    'multi finite exponent' => array('1e3', '1e3', true),
    'multi unknown' => array('U', 'U', true),
));
