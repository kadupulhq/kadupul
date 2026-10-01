<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

test('stored discovery data reaches the native mailer as escaped HTML', function (string $case): void {
    if (PHP_OS_FAMILY === 'Windows') {
        $this->markTestSkipped('The inert sendmail capture uses a POSIX shell.');
    }
    $root = dirname(__DIR__, 2);
    $directory = sys_get_temp_dir() . '/discovery email ' . bin2hex(random_bytes(8));
    mkdir($directory, 0700);
    mkdir($directory . '/include', 0700);
    $coverage = $this->getTestResultObject()->getCodeCoverage();
    try {
        copy($root . '/poller_automation.php', $directory . '/poller_automation.php');
        copy($root . '/tests/Fixtures/discovery-email-native.php', $directory . '/include/cli_check.php');
        file_put_contents($directory . '/sendmail', "#!/bin/sh\nexec " . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/tests/Fixtures/mailer-capture.php') . ' --capture ' . escapeshellarg($directory . '/message') . "\n");
        chmod($directory . '/sendmail', 0700);
        $environment = array_merge(getenv(), array('DISCOVERY_TEST_ROOT' => $root,'DISCOVERY_TEST_DIRECTORY' => $directory,'DISCOVERY_TEST_CASE' => $case,'DISCOVERY_TEST_COVERAGE' => $coverage !== null ? '1' : '0'));
        $command = array(PHP_BINARY,'-d','opcache.jit=0','-d','opcache.jit_buffer_size=0','-d','error_reporting=24575','-d','pcov.directory=/',$directory . '/poller_automation.php','--version');
        $process = proc_open($command, array(1 => array('pipe','w'),2 => array('pipe','w')), $pipes, $directory, $environment);
        $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        expect(proc_close($process))->toBe(0)->and($output)->toBe('');
        $state = json_decode(file_get_contents($directory . '/state.json'), true, flags: JSON_THROW_ON_ERROR);
        $sent = !in_array($case, array('disabled','missing','missing-after-read','no-recipient'), true);
        expect($state['sent'])->toBe($sent);
        if ($case === 'missing-after-read') {
            expect($state['log'])->toContain('not found for notification');
        }
        if ($sent) {
            $message = file_get_contents($directory . '/message');
            expect($message)->toContain('Subject: Discovery of <b>Network</b>');
            preg_match('/Content-Type: text\/html;[^\r\n]*(?:\r?\n[^\r\n]+)*?\r?\n\r?\n([A-Za-z0-9+\/=\r\n]+?)(?=\r?\n--)/', $message, $match);
            $body = base64_decode(preg_replace('/\s+/', '', $match[1]), true);
            expect($body)->toContain('<h1>Discovery of &lt;b&gt;Network&lt;/b&gt;</h1>')->toContain('host&quot;&#96;&lt;b&gt;x&lt;/b&gt;')->toContain('&lt;TO&gt;')->toContain('&lt;SUBJECT&gt;/24')->toContain('<tr><td>Started:</td><td></td></tr>')->not->toContain('<script>')->not->toContain('<b>Network</b>')->not->toContain('<SUBJECT>')->not->toContain('<TO>');
            if ($case === 'markup') {
                expect($body)->toContain('&lt;script&gt;alert(1)&lt;/script&gt;');
            } elseif ($case === 'placeholder') {
                expect($body)->toContain('<td>&lt;SUBJECT&gt;</td>');
            } elseif ($case === 'empty' || $case === 'null') {
                expect($body)->toContain('<i><u>None</u></i>');
            } elseif ($case === 'entity') {
                expect($body)->toContain('&lt;already&gt;')->not->toContain('&amp;lt;already');
            }
            expect($body)->toContain($case === 'existing' ? 'Existing Devices' : 'New Devices');
            expect($state['log'])->toContain('Email Notification Sent');
        }
        if ($coverage !== null) {
            foreach (glob($directory . '/*.coverage') as $report) {
                $coverage->merge(unserialize(file_get_contents($report)));
            }
        }
    } finally {
        $entries = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($entries as $entry) {
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($directory);
    }
})->with(array('markup','placeholder','empty','null','entity','existing','fallback','disabled','missing','missing-after-read','no-recipient'));
