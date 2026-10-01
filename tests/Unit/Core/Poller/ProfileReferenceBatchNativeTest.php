<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

test('collector reference batches verify all rows before transactional publication', function (string $case, bool $expected, int $rows, int $writes) {
    $root = dirname(__DIR__, 4);
    $directory = sys_get_temp_dir() . '/profile-index-' . bin2hex(random_bytes(8));
    mkdir($directory, 0700);
    try {
        $coverage = $this->getTestResultObject()->getCodeCoverage();
        $command = [PHP_BINARY, '-d', 'opcache.jit=0', '-d', 'opcache.jit_buffer_size=0', '-d', 'error_reporting=24575', '-d', 'pcov.directory=/', $root . '/tests/Fixtures/profile-parent-copy-unit.php', $case, $directory];
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
        $this->assertSame($expected, $state['success']);
        $this->assertCount($rows, $state['children']);
        $this->assertSame($writes, $state['reference_writes']);
        $this->assertFalse($state['active']);
        if ($expected) {
            $this->assertSame(range(2, $rows + 1), array_column($state['children'], 'id'));
            $this->assertSame([77], array_values(array_unique(array_column($state['children'], 'data_source_profile_id'))));
        } else {
            $this->assertSame([['id' => 1, 'data_source_profile_id' => 1, 'name' => 'old reference']], $state['children']);
        }
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
    'reference-batch-success' => ['reference-batch-success', true, 2001, 3],
    'reference-batch-payload' => ['reference-batch-payload', true, 200, 3],
    'reference-batch-late' => ['reference-batch-late', false, 1, 2],
    'reference-batch-corrupt' => ['reference-batch-corrupt', false, 1, 2],
    'reference-oversized' => ['reference-oversized', false, 1, 0],
    'reference-duplicate' => ['reference-duplicate', false, 1, 0],
    'reference-nonscalar' => ['reference-nonscalar', false, 1, 0],
    'reference-no-columns' => ['reference-no-columns', false, 1, 0],
]);
