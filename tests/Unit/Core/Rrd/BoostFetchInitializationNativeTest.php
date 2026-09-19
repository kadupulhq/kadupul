<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

test('production Boost fetch stops at failed writer initialization and restores caller state', function ($mode) {
    $root = dirname(__DIR__, 4);
    $directory = sys_get_temp_dir() . '/boost-fetch-init-' . bin2hex(random_bytes(8));
    mkdir($directory, 0700);
    foreach (array('poller', 'rrd') as $library) {
        file_put_contents($directory . '/' . $library . '.php', '<?php');
    }
    $coverage = $this->getTestResultObject()->getCodeCoverage();
    try {
        $process = proc_open(
            array(PHP_BINARY, '-d', 'display_errors=stderr', '-d', 'pcov.directory=' . $root,
                '-d', 'pcov.exclude=~/(include/vendor|tests)/~', $root . '/tests/Fixtures/boost-fetch-init-native.php',
                $root, $directory, $mode, $coverage !== null ? '1' : '0'),
            array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')),
            $pipes
        );
        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        expect(proc_close($process))->toBe(0, $error)->and($error)->toBe('');
        $result = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
        $exception = $mode === 'init-exception' ? 'Injected initialization exception' :
            (str_ends_with($mode, '-exception') ? 'Injected database exception' : null);
        expect($result['exception'])->toBe($exception)
            ->and($result['result'])->toBe(in_array($mode, array('failure', 'remote'), true) ? false : null)
            ->and($result['opens'])->toBe(in_array($mode, array('failure', 'owned-exception', 'owned-empty', 'init-exception'), true) ? 1 : 0)
            ->and($result['closed'])->toBe(str_starts_with($mode, 'owned-') ? 1 : 0)
            ->and($result['borrowed_open'])->toBeTrue()
            ->and($result['handler_restored'])->toBeTrue()->and($result['reporting_restored'])->toBeTrue();
        if ($mode === 'failure') {
            expect($result['messages'])->toHaveCount(1);
            expect($result['messages'][0])->toContain('pending samples retained');
        } else {
            expect(implode(' ', $result['messages']))->not->toContain('ERROR:');
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
})->with(array('failure', 'disabled', 'remote', 'owned-exception', 'borrowed-exception', 'init-exception', 'owned-empty', 'borrowed-empty'));
