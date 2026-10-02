<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

require_once dirname(__DIR__, 3) . '/Helpers/NativeChildCoverageEvidence.php';

test('native union helpers preserve filesystem and process results', function (string $case) {
    $root = dirname(__DIR__, 4);
    $directory = realpath(sys_get_temp_dir()) . '/helper-union-' . bin2hex(random_bytes(8));
    mkdir($directory, 0700);
    try {
        $coverage = $this->getTestResultObject()->getCodeCoverage();
        $command = [PHP_BINARY, '-d', 'auto_prepend_file=', '-d', 'opcache.jit=0', '-d', 'opcache.jit_buffer_size=0', '-d', 'error_reporting=24575', '-d', 'pcov.directory=/', '-d', 'sys_temp_dir=' . ($case === 'workspace-missing' ? $directory . '/missing' : $directory), $root . '/tests/Fixtures/helper-union-native.php', $case, $directory];
        if ($case === 'uid-fallback' || $case === 'uid-temp-refused') {
            array_splice($command, 1, 0, ['-d', 'disable_functions=posix_geteuid' . ($case === 'uid-temp-refused' ? ',tempnam' : '')]);
        }
        if ($case === 'command-refused') {
            array_splice($command, 1, 0, ['-d', 'disable_functions=proc_open']);
        }
        if ($coverage !== null) {
            $command[] = $directory;
        }
        $stderr = tmpfile();
        $this->assertIsResource($stderr);
        try {
            $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => $stderr], $pipes);
            $this->assertIsResource($process);
            fclose($pipes[0]);
            $output = stream_get_contents($pipes[1]);
            fclose($pipes[1]);
            $status = proc_close($process);
            rewind($stderr);
            $error = stream_get_contents($stderr);
        } finally {
            fclose($stderr);
        }
        $this->assertSame(0, $status, $error . $output);
        $this->assertSame('', $output . $error);
        $state = json_decode(file_get_contents($directory . '/result.json'), true, flags: JSON_THROW_ON_ERROR);
        switch ($case) {
            case 'filename-free': $this->assertSame($directory . '/template._01.xml', $state['result']);
                $this->assertSame(0, $state['files']);
                break;
            case 'filename-exhausted': $this->assertFalse($state['result']);
                $this->assertSame(19, $state['files']);
                break;
            case 'command-output': $this->assertSame('last', $state['result']);
                $this->assertSame(['first', 'last'], $state['output']);
                $this->assertSame(7, $state['status']);
                break;
            case 'command-empty': $this->assertNull($state['result']);
                $this->assertSame(['original'], $state['output']);
                $this->assertSame(3, $state['status']);
                break;
            case 'command-refused': $this->assertFalse($state['result']);
                $this->assertSame(['original'], $state['output']);
                $this->assertSame(99, $state['status']);
                break;
            case 'uid-posix': case 'uid-fallback': $this->assertSame(posix_geteuid(), $state['result']);
                $this->assertSame([], $state['probes']);
                break;
            case 'uid-temp-refused': $this->assertFalse($state['result']);
                $this->assertSame([], $state['probes']);
                break;
            case 'workspace-private': $this->assertIsString($state['result']);
                $this->assertSame(0700, $state['mode']);
                $this->assertDirectoryDoesNotExist($state['result']);
                break;
            case 'workspace-missing': $this->assertFalse($state['result']);
                $this->assertNull($state['mode']);
                break;
            case 'locks-acquired': $this->assertSame(1, $state['result']);
                $this->assertFalse($state['busy']);
                $this->assertTrue($state['config_preserved']);
                break;
            case 'locks-missing': $this->assertFalse($state['result']);
                $this->assertFalse($state['busy']);
                $this->assertTrue($state['config_preserved']);
                break;
        }
        if ($coverage !== null) {
            $reports = glob($directory . '/*.coverage');
            $this->assertCount(1, $reports);
            $source = match (true) {
                str_starts_with($case, 'filename-') => 'lib/api_device.php',
                str_starts_with($case, 'command-') => 'lib/poller.php',
                str_starts_with($case, 'uid-') => 'lib/csp_report_endpoint.php',
                default => 'lib/rrd_maintenance.php',
            };
            $sources = array_unique(array_merge(array('composer.lock', 'tests/composer.lock', 'tests/Fixtures/rrd-process-coverage.php', 'tests/Helpers/NativeChildCoverageEvidence.php', 'lib/rrd.php', 'src/Graphing/Infrastructure/Rrd/ProxyCipher.php', 'lib/dsdebug.php', 'lib/rrd_maintenance.php', 'lib/poller.php', 'lib/boost.php', 'lib/api_data_source.php', 'lib/rrdcheck.php', 'lib/dsstats.php', 'tests/Unit/Core/Helpers/HelperUnionNativeTest.php', 'src/Platform/Infrastructure/Legacy/LegacyComponentAutoloader.php'), [$source]));
            $markers = ['helper-result-observed'];
            $child = NativeChildCoverageEvidence::load($reports[0], $root, 'tests/Fixtures/helper-union-native.php', $case, $sources, $markers, [$source]);
            if ($case === 'filename-free') {
                $this->assertSame(27, NativeChildCoverageEvidence::verifyRejections($reports[0], $root, 'tests/Fixtures/helper-union-native.php', $case, $sources, $markers, [$source], 'lib/boost.php'));
            }
            $coverage->merge($child);
        }
    } finally {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $file) {
            $file->isLink() || !$file->isDir() ? unlink($file->getPathname()) : rmdir($file->getPathname());
        }
        rmdir($directory);
    }
})->with(['filename-free', 'filename-exhausted', 'command-output', 'command-empty', 'command-refused', 'uid-posix', 'uid-fallback', 'uid-temp-refused', 'workspace-private', 'workspace-missing', 'locks-acquired', 'locks-missing']);
