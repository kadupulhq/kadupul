<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

require_once dirname(__DIR__, 3) . '/Helpers/IncludeNotificationNativeEvidence.php';

test('missing includes log and notify once per debounce window through the native wrapper', function () {
    $root = dirname(__DIR__, 4);
    $directory = realpath(sys_get_temp_dir()) . '/include-notification-' . bin2hex(random_bytes(8));
    $this->assertTrue(mkdir($directory, 0700));
    try {
        $coverage = $this->getTestResultObject()->getCodeCoverage();
        $command = [PHP_BINARY, '-d', 'pcov.directory=/', '-d', 'pcov.exclude=~/(tests|include/vendor)/~',
            $root . '/' . IncludeNotificationNativeEvidence::PRODUCER, $directory];
        if ($coverage !== null) {
            $command[] = 'coverage';
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
        $json = file_get_contents($directory . '/result.json');
        $this->assertIsString($json);
        $result = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('missing.js', $result['first']);
        $this->assertSame('', $result['repeat']);
        $this->assertSame('missing.js', $result['expired']);
        $this->assertSame($result['first_log'], $result['repeat_log']);
        $this->assertSame($result['saved'], $result['repeat_saved']);
        $this->assertGreaterThanOrEqual(time() - 60, (int) $result['saved']);
        $this->assertGreaterThanOrEqual(time() - 60, (int) $result['saved_expired']);
        $warning = 'WARNING: Key Kadupul Include File ' . $directory . '/missing.js missing.  Please locate and replace this file';
        $this->assertStringContainsString(' - WEBUI ' . $warning, $result['first_log']);
        $this->assertSame(2, substr_count($result['expired_log'], $warning));
        $expected = ['Kadupul System Warning', 'WARNING:  Key Kadupul Include File ' . $directory . '/missing.js missing.  Please locate and replace this file'];
        $this->assertSame([$expected], $result['repeat_calls']);
        $this->assertSame([$expected, $expected], $result['calls']);
        $this->assertSame(['boot', 'bridge', 'shutdown', 'boot', 'bridge', 'shutdown'], $result['lifecycle']);
        IncludeNotificationNativeEvidence::verifyCopies($root, $directory);
        foreach (IncludeNotificationNativeEvidence::COPIES as $file) {
            $original = file_get_contents($directory . '/' . $file);
            $this->assertIsString($original);
            try {
                unlink($directory . '/' . $file);
                try {
                    IncludeNotificationNativeEvidence::verifyCopies($root, $directory);
                    $this->fail('Missing measured copy was accepted');
                } catch (RuntimeException $error) {
                    $this->assertStringContainsString('copy differs', $error->getMessage());
                }
                file_put_contents($directory . '/' . $file, $original . "\n// altered owned copy\n");
                try {
                    IncludeNotificationNativeEvidence::verifyCopies($root, $directory);
                    $this->fail('Altered measured copy was accepted');
                } catch (RuntimeException $error) {
                    $this->assertStringContainsString('copy differs', $error->getMessage());
                }
            } finally {
                file_put_contents($directory . '/' . $file, $original);
            }
        }
        if ($coverage !== null) {
            $arguments = [$directory . '/notification.coverage', $root, IncludeNotificationNativeEvidence::PRODUCER,
                IncludeNotificationNativeEvidence::SCENARIO, IncludeNotificationNativeEvidence::SOURCES,
                IncludeNotificationNativeEvidence::MARKERS, IncludeNotificationNativeEvidence::HITS];
            $measured = NativeChildCoverageEvidence::load(...$arguments);
            $this->assertSame(
                count(IncludeNotificationNativeEvidence::SOURCES) + count(IncludeNotificationNativeEvidence::MARKERS) + 10,
                NativeChildCoverageEvidence::verifyRejections(...[...$arguments, 'lib/functions.php'])
            );
            $coverage->merge($measured);
        }
    } finally {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $file) {
            $file->isLink() || !$file->isDir() ? unlink($file->getPathname()) : rmdir($file->getPathname());
        }
        rmdir($directory);
    }
});
