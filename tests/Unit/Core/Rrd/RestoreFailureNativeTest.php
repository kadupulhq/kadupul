<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

test('production restore rejects unsafe outputs and preserves recovery evidence', function ($mode) {
    $root = dirname(__DIR__, 4);
    $directory = sys_get_temp_dir() . '/restore-failure-' . bin2hex(random_bytes(8));
    mkdir($directory, 0700);
    $coverage = $this->getTestResultObject()->getCodeCoverage();
    try {
        $temporaryRoot = $mode === 'workspace-failure' ? $directory . '/missing' : $directory;
        $command = array(PHP_BINARY, '-d', 'sys_temp_dir=' . $temporaryRoot, '-d', 'pcov.directory=' . $root, '-d', 'pcov.exclude=~/(include/vendor|tests)/~');
        $disabled = array('no-posix' => 'posix_geteuid,posix_getegid', 'temporary-failure' => 'tempnam',
            'temporary-outside' => 'tempnam', 'mode-failure' => 'chmod', 'process-failure' => 'proc_open', 'metadata-failure' => 'stat');
        if (isset($disabled[$mode])) {
            $command = array_merge($command, array('-d', 'disable_functions=' . $disabled[$mode]));
        }
        $command = array_merge($command, array($root . '/tests/Fixtures/restore-failure-native.php',
            $root, $directory, $mode, $coverage !== null ? '1' : '0'));
        $process = proc_open($command, array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        expect(proc_close($process))->toBe(0, $error)->and($error)->toBe('');
        $result = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
        $called = in_array($mode, array('empty-output', 'missing-output', 'symlink-output', 'rename-failure', 'mode-failure'), true);
        expect($result['result'])->toBeFalse()->and($result['calls'])->toBe($called ? 1 : 0)
            ->and($result['original'])->toBe('retained original')
            ->and($result['recovery'])->toBe('retained recovery')
            ->and($result['temporary'])->toBe(array())
            ->and(glob($directory . '/kadupul-rrd-*'))->toBe(array());
        if ($mode === 'no-posix') {
            expect(implode(' ', $result['messages']))->toContain('Enable PHP POSIX');
        } elseif ($mode === 'rename-failure') {
            expect(implode(' ', $result['messages']))->toContain('could not replace original');
            expect(file_get_contents($directory . '/directory.rrd/retained'))->toBe('retained directory');
        } elseif ($mode === 'temporary-failure') {
            expect(implode(' ', $result['messages']))->toContain('could not create a temporary file');
        } elseif ($mode === 'temporary-outside') {
            expect(implode(' ', $result['messages']))->toContain('outside storage');
            expect(glob($directory . '/outside/*'))->toBe(array());
        } elseif ($mode === 'mode-failure') {
            expect(implode(' ', $result['messages']))->toContain('could not preserve ownership');
        }
        if ($coverage !== null) {
            $reports = glob($directory . '/*.coverage');
            expect($reports)->toHaveCount(1);
            $coverage->merge(unserialize(file_get_contents($reports[0])));
        }
    } finally {
        $paths = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($paths as $path) {
            $path->isDir() && !$path->isLink() ? rmdir($path->getPathname()) : unlink($path->getPathname());
        }
        rmdir($directory);
    }
})->with(array('no-posix', 'workspace-untrusted', 'remote-unsafe', 'unsafe-path', 'symlink-target',
    'empty-output', 'missing-output', 'symlink-output', 'rename-failure', 'workspace-failure',
    'temporary-failure', 'temporary-outside', 'mode-failure', 'process-failure', 'metadata-failure'));
