<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

test('dead-letter and replay keep every sample on success and leave queues unchanged on failure', function () {
    $root = dirname(__DIR__, 4);
    $directory = sys_get_temp_dir() . '/rejected-limits-' . bin2hex(random_bytes(8));
    mkdir($directory, 0700);
    $coverage = $this->getTestResultObject()->getCodeCoverage();
    try {
        $process = proc_open(
            array(PHP_BINARY, '-d', 'display_errors=stderr', '-d', 'pcov.directory=' . $root, '-d', 'pcov.exclude=~/(include/vendor|tests)/~',
                $root . '/tests/Fixtures/rejected-samples-native.php', $root, $directory, $coverage !== null ? '1' : '0'),
            array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')),
            $pipes
        );
        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        expect(proc_close($process))->toBe(0, $error)->and($error)->toBe('');
        $results = json_decode($output, true, 512, JSON_THROW_ON_ERROR);

        // Ten queued samples over a four-sample limit: three whole groups move.
        expect($results['row-limit'][0])->toBe(6)->and($results['row-limit'][1])->toBe(array(4, 6))
            ->and($results['row-limit'][2])->toContain('DDL rejected')
            ->and(end($results['row-limit'][2]))->toStartWith('ERROR: Moved 6 rejected samples to poller_output_rejected: ')
            ->toContain('more than 4 retained samples');
        expect($results['under-limit'])->toBe(array(0, array(4, null), array()))
            ->and($results['empty'])->toBe(array(0, array(0, null), array()))
            ->and($results['foreign-table'][0])->toBeFalse()
            ->and($results['archive'][0])->toBe(1)
            ->and(end($results['archive'][2]))->toContain('retained longer than 24 hours');

        foreach (array('inspect-failure', 'select-failure', 'begin-failure', 'copy-failure', 'commit-failure') as $failure) {
            // A failed move never removes a sample from the live queue.
            expect($results[$failure][0])->toBeFalse($failure)->and($results[$failure][1][0])->toBe(10, $failure);
        }
        expect($results['copy-failure'][1][1])->toBe(0)
            ->and(end($results['inspect-failure'][2]))->toContain('Unable to inspect rejected samples for Local Data ID 1')
            ->and(end($results['copy-failure'][2]))->toContain('Unable to dead-letter rejected samples for Local Data ID 1');

        expect($results['replay'][0])->toBe(array(6, 6, 0))->and($results['replay'][1])->toBe(array(10, 0))
            ->and($results['replay-missing'][0])->toBe(0);
        foreach (array('replay-read-failure', 'replay-begin-failure', 'replay-delete-failure', 'replay-commit-failure') as $failure) {
            // A failed replay leaves the dead-lettered samples where they were.
            expect($results[$failure][0])->toBeFalse($failure)->and($results[$failure][1])->toBe(array(4, 6), $failure);
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
});
