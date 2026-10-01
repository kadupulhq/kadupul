<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

use PHPUnit\Framework\TestCase;

final class StoredUnitExponentNativeTest extends TestCase
{
    /** @dataProvider exponents */
    public function testStoredValueReachesCompleteGraphCommand(string $value, bool $valid, int $graphId): void
    {
        $root = dirname(__DIR__, 4);
        $directory = sys_get_temp_dir() . '/stored-exponent-' . bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        try {
            $process = proc_open(array(PHP_BINARY, $root . '/tests/fixtures/unit-exponent-graph-native.php', $value, (string) $graphId, $directory), array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            self::assertSame(0, proc_close($process), $stderr . $stdout);
            self::assertSame('', $stderr . $stdout);
            $state = json_decode(file_get_contents($directory . '/result.json'), true, flags: JSON_THROW_ON_ERROR);
            self::assertSame($value, $state['stored']);
            self::assertStringContainsString('rrdtool graph', $state['html']);
            self::assertSame($valid ? 1 : 0, substr_count($state['html'], '--units-exponent='));
            if ($valid) {
                self::assertStringContainsString('--units-exponent=' . $value . ' ', $state['html']);
            }
        } finally {
            foreach (glob($directory . '/*') as $file) {
                unlink($file);
            }
            rmdir($directory);
        }
    }

    public static function exponents(): array
    {
        $cases = array();
        foreach (array(7, 8) as $graphId) {
            foreach (array('-6', '0', '3', '+3', ' 3', '-', '3--foo', "-6\n") as $value) {
                $cases[] = array($value, in_array($value, array('-6', '0', '3'), true), $graphId);
            }
        }
        return $cases;
    }
}
