<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

test('on-demand Boost distinguishes fallback path failure from removed sources', function (string $mode, bool $owned): void {
    $root = dirname(__DIR__, 4);
    $directory = sys_get_temp_dir() . '/boost-fallback-' . bin2hex(random_bytes(8));
    mkdir($directory, 0700);
    file_put_contents($directory . '/rrd.php', '<?php');
    file_put_contents($directory . '/sample.rrd', 'fixture exists');
    $coverage = $this->getTestResultObject()->getCodeCoverage();
    try {
        $process = proc_open(
            array(PHP_BINARY, '-d', 'pcov.directory=' . $root,
                '-d', 'pcov.exclude=~/(include/vendor|tests)/~', $root . '/tests/Fixtures/boost-on-demand-native.php',
                $root, $directory, $mode, $owned ? '1' : '0', $coverage !== null ? '1' : '0'),
            array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')),
            $pipes
        );
        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        expect(proc_close($process))->toBe(0)->and($error)->toBe('');
        $observed = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
        $failed = $mode === 'fallback-failure';
        expect($observed['result'])->toBe($failed ? -1 : 2)
            ->and($observed['deletes'])->toHaveCount($failed ? 0 : 2)
            ->and($observed['updates'])->toHaveCount($mode === 'fallback-valid' ? 1 : 0)
            ->and($observed['closed'])->toBe($owned ? 1 : 0)
            ->and($observed['borrowed_open'])->toBeTrue()
            ->and($observed['handler_restored'])->toBeTrue()
            ->and($observed['reporting_restored'])->toBeTrue();
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
})->with(array('fallback-failure', 'fallback-valid', 'fallback-absent', 'fallback-empty'))->with(array(false, true));
