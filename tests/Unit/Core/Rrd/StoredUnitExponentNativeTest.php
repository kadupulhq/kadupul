<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

dataset('stored unit exponents', function () {
    $cases = array();
    foreach (array(7, 8) as $graphId) {
        foreach (array('-6', '0', '3', '+3', ' 3', '-', '', '3--foo', '3 --foo', "-6\n") as $value) {
            $cases[] = array($value, in_array($value, array('-6', '0', '3'), true), $graphId);
        }
    }
    return $cases;
});

test('stored graph and aggregate exponents reach the complete graph command', function (string $value, bool $valid, int $graphId): void {
    $root = dirname(__DIR__, 4);
    $directory = sys_get_temp_dir() . '/stored-exponent-' . bin2hex(random_bytes(8));
    mkdir($directory, 0700);
    try {
        $process = proc_open(array(PHP_BINARY, $root . '/tests/fixtures/unit-exponent-graph-native.php', $value, (string) $graphId, $directory), array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $this->assertSame(0, proc_close($process), $stderr . $stdout);
        $this->assertSame('', $stderr . $stdout);
        $state = json_decode(file_get_contents($directory . '/result.json'), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame($value, $state['stored']);
        $this->assertStringContainsString('rrdtool graph', $state['html']);
        $this->assertSame($valid ? 1 : 0, substr_count($state['html'], '--units-exponent='));
        if ($valid) {
            $this->assertStringContainsString('--units-exponent=' . $value . ' ', $state['html']);
        }
    } finally {
        foreach (glob($directory . '/*') as $file) {
            unlink($file);
        }
        rmdir($directory);
    }
})->with('stored unit exponents');
