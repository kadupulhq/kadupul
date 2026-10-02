<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

test('native string selectors retain configuration validation and output contracts', function () {
    $root = dirname(__DIR__, 4);
    $directory = realpath(sys_get_temp_dir()) . '/php80-native-' . bin2hex(random_bytes(8));
    mkdir($directory, 0700);
    try {
        $coverage = $this->getTestResultObject()->getCodeCoverage();
        $command = [PHP_BINARY, '-d', 'error_reporting=E_ALL & ~E_DEPRECATED', '-d', 'opcache.jit=0', '-d', 'opcache.jit_buffer_size=0', '-d', 'pcov.directory=/', '-d', 'disable_functions=openlog,closelog,syslog', $root . '/tests/Fixtures/php80-string-native.php', $directory];
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
        $result = json_decode(file_get_contents($directory . '/result.json'), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame([true, true, false], $result['remote_paths']);
        $this->assertSame(5, $result['selective_level']);
        $this->assertSame(['<span>body</span>', '<span class="deviceUp">body</span>', '<span class="deviceWarning">body</span>', '<span class="deviceDown">body</span>', '<span class="deviceDown">body</span>', '<span class="deviceUnknown">body</span>'], $result['messages']);
        $this->assertSame(['m-d-Y H:i:s', 'M-d-Y H:i:s', 'd-m-Y H:i:s', 'd-M-Y H:i:s', 'Y-m-d H:i:s', 'Y-M-d H:i:s', 'Y-m-d H:i:s'], $result['dates']);
        $this->assertSame([true, true, true, false, true, true, false, true, false, true, true, true, false, true, true, false, true, false, true], $result['log_filters']);
        $this->assertSame([true, false, '[::1]', 'invalid'], $result['addresses']);
        $this->assertSame([true, false], $result['sensitive']);
        $this->assertSame([true, 'AB CD'], $result['hex']);
        $this->assertSame([['name' => '', 'email' => 'local-user'], ['name' => '', 'email' => 'person@example.test'], ['name' => 'Name ', 'email' => 'person@example.test']], $result['email']);
        $this->assertTrue($result['tables']);
        $this->assertSame(['?action=edit&header=false', 'graph.php?header=false', 'graph.php?header=false'], $result['urls']);
        $this->assertSame(['bad', 'malformed', 'backtrack'], $result['validation_fields']);
        $this->assertStringContainsString("Value 'invalid' Failed REGEX '^valid$'", $result['validation_log']);
        $this->assertStringContainsString('(PCRE: Internal error)', $result['validation_log']);
        $this->assertStringContainsString('(PCRE: Backtrack limit exhausted)', $result['validation_log']);
        $this->assertSame([[LOG_CRIT, 'NATIVE: ERROR: failure'], [LOG_WARNING, 'NATIVE: WARNING: warning'], [LOG_INFO, 'NATIVE: STATS: stats'], [LOG_INFO, 'NATIVE: NOTICE: notice']], $result['syslog']);
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
});
