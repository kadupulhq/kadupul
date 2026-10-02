<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

use PHPUnit\Framework\TestCase;

final class LegacyRequestContextNativeTest extends TestCase
{
    /** @dataProvider bootModes */
    public function testProductionWrappersPreserveRequestSelectionSanitizationAndLogging(string $mode): void
    {
        $directory = sys_get_temp_dir() . '/request-context-' . bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        $coverage = $this->getTestResultObject()->getCodeCoverage();
        try {
            $command = array(PHP_BINARY, '-d', 'error_reporting=24575', '-d', 'auto_prepend_file=', '-d', 'pcov.directory=/', '-d', 'pcov.exclude=~/(include/vendor|tests)/~', __DIR__ . '/../Fixtures/request-context-native.php', $directory, $mode);
            if ($coverage !== null) {
                $command[] = 'coverage';
            }
            $process = proc_open($command, array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
            self::assertIsResource($process);
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            self::assertSame(0, proc_close($process), $stdout . $stderr);
            self::assertSame('', $stderr);
            $result = json_decode($stdout, true, 512, JSON_THROW_ON_ERROR);
            self::assertSame(array(
                array('page' => 'index.php', 'full_page' => '/admin/index.php', 'uri' => '/raw.php?a=1&b=2'),
                array('page' => 'fallback.php', 'full_page' => '/srv/fallback.php', 'uri' => 'fallback.php?x=1&y=2'),
                array('page' => 'index.php', 'full_page' => '/index.php', 'uri' => 'index.php'),
                array('page' => false, 'full_page' => false, 'uri' => ''),
                array('page' => 'index.php', 'full_page' => '/index.php', 'uri' => '/index.php?x=bad')
            ), $result['results']);
            self::assertSame(3, substr_count($result['log'], 'ERROR: unable to determine current_page'));
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

    public function bootModes(): array
    {
        return array(array('autoload'), array('early'));
    }
}
