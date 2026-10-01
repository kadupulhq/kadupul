<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

use PHPUnit\Framework\TestCase;

final class ProfileCollectorCliNativeTest extends TestCase
{
    /** @dataProvider cases */
    public function testCliRetainsFailedPollerSynchronization(bool $failure, bool $selected, bool $state_failure = false): void
    {
        $root = dirname(__DIR__, 2);
        $directory = sys_get_temp_dir() . '/profile-collector-cli-' . bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        try {
            $coverage = $this->getTestResultObject()->getCodeCoverage();
            $scenario = array('cli' => true, 'failure' => $failure, 'selected' => $selected, 'completion_failure' => $state_failure);
            $failure = $failure || $state_failure;
            $command = array(PHP_BINARY, $root . '/tests/Fixtures/profile-collector-replication-native.php', json_encode($scenario, JSON_THROW_ON_ERROR), $directory);
            if ($coverage !== null) {
                $command[] = $directory;
            }
            $process = proc_open($command, array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
            $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            self::assertSame(0, proc_close($process), $output);
            self::assertSame('', $output);
            $state = json_decode(file_get_contents($directory . '/result.json'), true, flags: JSON_THROW_ON_ERROR);
            self::assertSame($failure ? 1 : 0, $state['status']);
            self::assertSame('', $state['stdout']);
            self::assertSame('', $state['stderr']);
            self::assertTrue($state['unregistered']);
            self::assertSame($failure ? 'on' : '', $state['pollers'][0]['requires_sync']);
            self::assertSame($failure, $state['pollers'][0]['last_sync'] === '');
            self::assertSame($selected ? 'on' : '', $state['pollers'][1]['requires_sync']);
            self::assertSame($selected, $state['pollers'][1]['last_sync'] === '');
            self::assertSame($failure, str_contains(implode('\n', $state['log']), 'replication failed'));
            if ($coverage !== null) {
                foreach (glob($directory . '/*.coverage') as $report) {
                    $coverage->merge(unserialize(file_get_contents($report)));
                }
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
        return array(array(false, false), array(true, false), array(false, true), array(true, true), array(false, false, true), array(false, true, true));
    }
}
