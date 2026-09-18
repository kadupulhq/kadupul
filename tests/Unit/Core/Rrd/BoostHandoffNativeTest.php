<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

test('production Boost handoff requires successful writes to the selected database', function ($mode, $connection) {
    $root = dirname(__DIR__, 4);
    $directory = sys_get_temp_dir() . '/boost-handoff-' . bin2hex(random_bytes(8));
    mkdir($directory, 0700);
    $coverage = $this->getTestResultObject()->getCodeCoverage();
    try {
        // Database acknowledgements are injected; the handoff and buffer splitting are production code.
        $process = proc_open(
            array(PHP_BINARY, '-d', 'display_errors=stderr', '-d', 'pcov.directory=' . $root,
                '-d', 'pcov.exclude=~/(include/vendor|tests)/~', $root . '/tests/Fixtures/boost-handoff-native.php',
                $root, $directory, $mode, $connection, $coverage !== null ? '1' : '0'),
            array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')),
            $pipes
        );
        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        expect(proc_close($process))->toBe(0, $error)->and($error)->toBe('');
        $result = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
        $failed = str_ends_with($mode, 'failure');
        expect($result['result'])->toBe($failed ? null : false)
            ->and($result['handler_restored'])->toBeTrue()->and($result['samples_unchanged'])->toBeTrue()
            ->and($result['writes'])->toHaveCount($mode === 'split-success' ? 2 : 1);
        foreach ($result['writes'] as $write) {
            expect(strlen($write['sql']))->toBeLessThanOrEqual(str_starts_with($mode, 'split-') ? 180 : 1000);
            expect($write['connection'])->toBe($connection === 'online' ? 'primary-database' : false);
            expect($write['sql'])->toStartWith('INSERT INTO poller_output_boost ')
                ->toEndWith(' ON DUPLICATE KEY UPDATE output=VALUES(output)');
        }
        if ($mode === 'split-failure') {
            expect($result['writes'][0]['sql'])->toContain("'42'")->not->toContain("'43'");
        } elseif (!$failed) {
            expect(implode(' ', array_column($result['writes'], 'sql')))->toContain("'42'")->toContain("'43'");
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
})->with(array('split-failure', 'split-success', 'final-failure', 'final-success'))
    ->with(array('local', 'online', 'offline'));
