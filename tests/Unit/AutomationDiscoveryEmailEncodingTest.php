<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

test('stored discovery data reaches the native mailer as escaped HTML', function (string $case): void {
    if (PHP_OS_FAMILY === 'Windows') {
        $this->markTestSkipped('The inert sendmail capture uses a POSIX shell.');
    }
    $root = dirname(__DIR__, 2);
    $directory = sys_get_temp_dir() . (str_starts_with($case, 'worker-snmp-') ? '/discovery-email-' : '/discovery email ') . bin2hex(random_bytes(8));
    mkdir($directory, 0700);
    mkdir($directory . '/include', 0700);
    $coverage = $this->getTestResultObject()->getCodeCoverage();
    try {
        copy($root . '/poller_automation.php', $directory . '/poller_automation.php');
        copy($root . '/tests/Fixtures/discovery-email-native.php', $directory . '/include/cli_check.php');
        file_put_contents($directory . '/sendmail', "#!/bin/sh\nexec " . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/tests/Fixtures/mailer-capture.php') . ' --capture ' . escapeshellarg($directory . '/message') . "\n");
        chmod($directory . '/sendmail', 0700);
        if (str_starts_with($case, 'worker-snmp-')) {
            file_put_contents($directory . '/snmpget', str_replace('#!/usr/bin/env php', '#!' . PHP_BINARY, file_get_contents($root . '/tests/Fixtures/discovery-snmp-response.php')));
            chmod($directory . '/snmpget', 0700);
        }
        $environment = array_merge(getenv(), array('DISCOVERY_TEST_ROOT' => $root, 'DISCOVERY_TEST_DIRECTORY' => $directory, 'DISCOVERY_TEST_CASE' => $case, 'DISCOVERY_TEST_COVERAGE' => $coverage !== null ? '1' : '0'));
        $command = array(PHP_BINARY, '-d', 'opcache.jit=0', '-d', 'opcache.jit_buffer_size=0', '-d', 'error_reporting=24575', '-d', 'pcov.directory=/', $directory . '/poller_automation.php', '--version');
        if (str_starts_with($case, 'worker-')) {
            array_pop($command);
            $command[] = '--network=7';
            if ($case !== 'worker-parent') {
                $command[] = '--thread=1';
            }
        }
        if (str_starts_with($case, 'master-')) {
            array_pop($command);
            $command[] = '--master';
        }
        $process = proc_open($command, array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes, $directory, $environment);
        $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $this->assertSame(0, proc_close($process), $output . (is_file($directory . '/state.json') ? file_get_contents($directory . '/state.json') : ''));
        expect($output)->toBe('');
        $state = json_decode(file_get_contents($directory . '/state.json'), true, flags: JSON_THROW_ON_ERROR);
        if ($case === 'worker-parent') {
            expect($state['error'])->toBeNull()->and($state['processes'])->toBe(array(array('pid' => 888, 'network_id' => 8, 'task' => 'collector')))->and($state['ips'])->toBe(array(8))
                ->and((int) $state['network']['up_hosts'])->toBe(0)->and((int) $state['network']['snmp_hosts'])->toBe(0)
                ->and($state['network']['last_started'])->not->toBeNull()->and((float) $state['network']['last_runtime'])->toBeGreaterThanOrEqual(20.0)
                ->and(array_slice($state['args'], 1))->toBe(array('--poller=1', '--thread=1', '--network=7'));
        } elseif ($case === 'worker-cancel') {
            expect($state['error'])->toBeNull()->and($state['ips'])->toBe(array(8))->and($state['processes'])->toBe(array(array('pid' => 71, 'network_id' => 7, 'task' => 'tmaster')));
        } elseif (str_starts_with($case, 'master-')) {
            expect($state['tasks'])->toBe(0)->and($state['error'])->toBeNull();
            if ($case === 'master-snmp') {
                expect($state['log'])->toContain('SNMP ID is not set');
            } elseif ($case === 'master-schedule') {
                expect($state['log'])->toContain('Not time to Run Discovery');
            }
        } elseif (str_starts_with($case, 'worker-')) {
            $snmp = str_starts_with($case, 'worker-snmp-');
            $count = $case === 'worker-deleted' || $case === 'worker-snmp-duplicate' ? 0 : 1;
            expect($state['error'])->toBeNull()->and($state['task'])->toBe(array('status' => 'done', 'up_hosts' => $snmp ? 0 : $count, 'snmp_hosts' => $count))
                ->and($state['ips'])->toBe(array(array('ip_address' => '127.0.0.1', 'network_id' => 7, 'status' => 2), array('ip_address' => 'invalid-address', 'network_id' => 7, 'status' => 2), array('ip_address' => 'unrelated-address', 'network_id' => 8, 'status' => 0)));
            if ($snmp) {
                $found = array_values(array_filter($state['found'], static fn($row) => $row['ip'] === '127.0.0.1'));
                expect(count($found))->toBe($case === 'worker-snmp-duplicate' ? 0 : 1);
                if ($found) {
                    expect($found[0]['sysName'])->toBe('FixtureNode')->and((int) $found[0]['snmp'])->toBe(1)->and((int) $found[0]['up'])->toBe(1);
                }
            }
        } elseif ($case === 'host-fields' || $case === 'host-fields-empty') {
            expect($state['error'])->toBeNull();
            $existing = ['id' => 43, 'snmp_sysDescr' => 'existing', 'snmp_sysObjectID' => 'existing', 'snmp_sysUptimeInstance' => 'existing', 'snmp_sysContact' => 'existing', 'snmp_sysName' => 'existing', 'snmp_sysLocation' => 'existing'];
            $expected = $case === 'host-fields' ? ['id' => 42, 'snmp_sysDescr' => 'Linux <b>node</b>', 'snmp_sysObjectID' => '1.3.6.1', 'snmp_sysUptimeInstance' => '123', 'snmp_sysContact' => 'contact', 'snmp_sysName' => 'node', 'snmp_sysLocation' => 'site'] : array_replace($existing, ['id' => 42, 'snmp_sysUptimeInstance' => '0']);
            expect($state['hosts'])->toBe([$expected, $existing]);
        } elseif ($case === 'queue') {
            expect((int) $state['running'])->toBe(1)
                ->and($state['task'])->toBe(array('status' => 'done', 'up_hosts' => 1, 'snmp_hosts' => 1, 'heartbeat' => '2026-01-01 00:00:00'))
                ->and($state['ip'])->toBe(array(array('network_id' => 7, 'status' => 2), array('network_id' => 8, 'status' => 0)))
                ->and((int) $state['down'])->toBe(0)
                ->and($state['remaining'])->toBe(array(72))
                ->and($state['remainingIps'])->toBe(array(8));
        } else {

            $sent = !in_array($case, array('disabled', 'missing', 'missing-after-read', 'no-recipient', 'admin-missing'), true);
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
})->with(array('markup', 'placeholder', 'empty', 'null', 'entity', 'existing', 'fallback', 'disabled', 'missing', 'missing-after-read', 'no-recipient', 'queue', 'worker-existing', 'worker-deleted', 'master-empty', 'master-snmp', 'master-schedule', 'worker-snmp-none', 'worker-snmp-match', 'worker-snmp-duplicate', 'worker-parent', 'worker-cancel', 'admin-missing', 'host-fields', 'host-fields-empty'));
