<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

use PHPUnit\Framework\TestCase;

final class ProfileParentCopyNativeTest extends TestCase
{
    /** @dataProvider cases */
    public function testParentCopyAcknowledgesDeliveryBeforeReferences(string $case): void
    {
        $root = dirname(__DIR__, 2);
        $directory = sys_get_temp_dir() . '/profile-parent-copy-' . bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        try {
            $coverage = $this->getTestResultObject()->getCodeCoverage();
            $command = array(PHP_BINARY, '-d', 'opcache.jit=0', '-d', 'opcache.jit_buffer_size=0', '-d', 'error_reporting=24575', '-d', 'pcov.directory=/', $root . '/tests/Fixtures/profile-parent-copy-unit.php', $case, $directory);
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
            self::assertSame(in_array($case, array('success', 'zero', 'missing-table'), true), $state['success']);
            if ($case === 'success') {
                self::assertSame(array(array('id' => 1, 'name' => 'Updated default'), array('id' => 77, 'name' => 'Custom profile')), $state['rows']);
            } elseif ($case === 'missing-table') {
                self::assertSame(array(array('id' => 77, 'name' => 'Custom profile')), $state['rows']);
            } elseif ($case !== 'create-failure' && $case !== 'missing-definition') {
                self::assertSame(array(array('id' => 1, 'name' => 'Old default')), $state['rows']);
            } else {
                self::assertSame(array(), $state['rows']);
            }
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
        return array_map(static fn($case) => array($case), array('success', 'zero', 'negative', 'invalid', 'missing-parent', 'query-failure', 'copy-failure', 'copy-exception', 'missing-table', 'create-failure', 'missing-definition'));
    }
}
