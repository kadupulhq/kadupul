<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

use PHPUnit\Framework\TestCase;

final class ProfileRetryOwnershipNativeTest extends TestCase
{
    /** @dataProvider cases */
    public function testRetryOwnershipPrecedesCollectorAvailability(string $case): void
    {
        $root = dirname(__DIR__, 2);
        $directory = sys_get_temp_dir() . '/profile-retry-' . bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        try {
            $coverage = $this->getTestResultObject()->getCodeCoverage();
            $command = [PHP_BINARY, '-d', 'opcache.jit=0', '-d', 'opcache.jit_buffer_size=0', '-d', 'error_reporting=24575', '-d', 'pcov.directory=/', $root . '/tests/Fixtures/profile-retry-ownership-unit.php', $case, $directory];
            if ($coverage !== null) {
                $command[] = $directory;
            }
            $stderr = tmpfile();
            self::assertIsResource($stderr);
            try {
                $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => $stderr], $pipes);
                self::assertIsResource($process);
                fclose($pipes[0]);
                $output = stream_get_contents($pipes[1]);
                fclose($pipes[1]);
                $status = proc_close($process);
                rewind($stderr);
                $error = stream_get_contents($stderr);
            } finally {
                fclose($stderr);
            }
            self::assertSame(0, $status, $error . $output);
            self::assertSame('', $output . $error);
            $state = json_decode(file_get_contents($directory . '/result.json'), true, flags: JSON_THROW_ON_ERROR);
            self::assertFalse($state['result']);
            $invalid = $case === 'device-invalid';
            $rejected = str_contains($case, 'mark-failure');
            self::assertSame($invalid ? 0 : 1, $state['writes']);
            self::assertSame($invalid || $rejected || $case === 'device-unavailable' ? 0 : 1, $state['connections']);
            self::assertSame(in_array($case, ['device-unavailable', 'device-connect-failure'], true) ? 1 : 0, $state['availability']);
            self::assertSame($invalid || $rejected ? '' : 'on', $state['poller']['requires_sync']);
            self::assertSame('previous successful sync', $state['poller']['last_sync']);
            self::assertSame($rejected, str_contains(implode('\n', $state['messages']), 'synchronization required'));
            if ($coverage !== null) {
                $reports = glob($directory . '/*.coverage');
                self::assertCount(1, $reports);
                $coverage->merge(unserialize(file_get_contents($reports[0])));
            }
        } finally {
            foreach (glob($directory . '/*') as $file) {
                unlink($file);
            }
            rmdir($directory);
        }
    }

    public static function cases(): array
    {
        return array_map(static fn($case) => [$case], ['all-mark-failure', 'data-mark-failure', 'all-unavailable', 'data-unavailable', 'device-mark-failure', 'device-unavailable', 'device-connect-failure', 'device-invalid']);
    }
}
