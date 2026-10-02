<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

test('retry ownership and upgrade guards precede collector replication', function (string $case) {
    $root = dirname(__DIR__, 4);
    $directory = sys_get_temp_dir() . '/profile-retry-' . bin2hex(random_bytes(8));
    mkdir($directory, 0700);
    try {
        $coverage = $this->getTestResultObject()->getCodeCoverage();
        $command = [PHP_BINARY, '-d', 'opcache.jit=0', '-d', 'opcache.jit_buffer_size=0', '-d', 'error_reporting=24575', '-d', 'pcov.directory=/', $root . '/tests/Fixtures/profile-retry-ownership-unit.php', $case, $directory];
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
        $this->assertFalse($state['result']);
        $invalid = $case === 'device-invalid';
        $guards = str_ends_with($case, 'guards-missing');
        $partial = str_starts_with($case, 'settings-');
        $rejected = str_contains($case, 'mark-failure');
        $this->assertSame($invalid || $partial ? 0 : 1, $state['writes']);
        $this->assertSame($invalid || $rejected || $guards || $case === 'device-unavailable' ? 0 : 1, $state['connections']);
        $this->assertSame(in_array($case, ['device-unavailable', 'device-connect-failure'], true) ? 1 : 0, $state['availability']);
        $this->assertSame($invalid || $rejected || $partial ? '' : 'on', $state['poller']['requires_sync']);
        $this->assertSame('previous successful sync', $state['poller']['last_sync']);
        $this->assertSame($guards ? 1 : 0, $state['guard_reads']);
        $this->assertSame($guards ? ['poller_sync_failed'] : [], $state['ui_messages']);
        $this->assertSame('1.2.33', $state['remote_version']);
        $this->assertSame($rejected, str_contains(implode('\n', $state['messages']), 'synchronization required'));
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
})->with(array_map(static fn($case) => [$case], ['all-mark-failure', 'data-mark-failure', 'all-unavailable', 'data-unavailable', 'device-mark-failure', 'device-unavailable', 'device-connect-failure', 'device-invalid', 'all-guards-missing', 'data-guards-missing', 'settings-guards-missing']));
