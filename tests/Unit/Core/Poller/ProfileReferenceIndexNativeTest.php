<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

test('collector reference index metadata rejects incomplete migrations', function (string $case, bool $expected) {
    $root = dirname(__DIR__, 4);
    $directory = sys_get_temp_dir() . '/profile-index-' . bin2hex(random_bytes(8));
    mkdir($directory, 0700);
    try {
        $coverage = $this->getTestResultObject()->getCodeCoverage();
        $command = [PHP_BINARY, '-d', 'opcache.jit=0', '-d', 'opcache.jit_buffer_size=0', '-d', 'error_reporting=24575', '-d', 'pcov.directory=/', $root . '/tests/Fixtures/profile-reference-index-unit.php', $case, $directory];
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
        $this->assertSame($expected, $state['result']);
        $this->assertSame(1, $state['queries']);
        if ($coverage !== null) {
            $reports = glob($directory . '/*.coverage');
            $this->assertCount(1, $reports);
            $coverage->merge(unserialize(file_get_contents($reports[0])));
        }
    } finally {
        foreach (glob($directory . '/*') as $file) {
            unlink($file);
        }
        rmdir($directory);
    }
})->with([
    'MySQL visible' => ['mysql', true],
    'MariaDB active' => ['mariadb', true],
    'visibility metadata unavailable' => ['legacy', true],
    'query refused' => ['query-failure', false],
    'index missing' => ['missing', false],
    'ambiguous index' => ['multiple', false],
    'wrong leading column' => ['wrong-column', false],
    'wrong sequence' => ['wrong-sequence', false],
    'missing prefix metadata' => ['missing-prefix', false],
    'prefix index' => ['prefix', false],
    'unique index' => ['unique', false],
    'invisible MySQL index' => ['invisible', false],
    'ignored MariaDB index' => ['ignored', false],
]);
