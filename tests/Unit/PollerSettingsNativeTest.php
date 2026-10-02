<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

use PHPUnit\Framework\TestCase;

final class PollerSettingsNativeTest extends TestCase
{
    public function testSettingsDropTheInertOptionAndPreserveTheAdjacentCheckbox(): void
    {
        $process = proc_open(array(PHP_BINARY, '-d', 'opcache.jit=0', '-d', 'opcache.jit_buffer_size=0', '-d', 'error_reporting=24575', __DIR__ . '/../Fixtures/poller-settings-native.php'), array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
        self::assertIsResource($process);
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process), $errors);
        self::assertSame('', $errors);
        $result = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
        foreach ($result['tabs'] as $keys) {
            self::assertNotContains('poller_refresh_output_table', $keys);
        }
        self::assertSame('checkbox', $result['neighbor']['method']);
        self::assertSame('', $result['neighbor']['default']);
    }
}
