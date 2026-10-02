<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

require_once dirname(__DIR__, 3) . '/Helpers/PredicateNativeEvidence.php';

test('native predicates preserve rendering redirects and resource replication', function () {
    $root = dirname(__DIR__, 4);
    $directory = realpath(sys_get_temp_dir()) . '/predicate-native-' . bin2hex(random_bytes(8));
    mkdir($directory, 0700);
    try {
        $coverage = $this->getTestResultObject()->getCodeCoverage();
        $command = [PHP_BINARY, '-d', 'error_reporting=E_ALL & ~E_DEPRECATED', '-d', 'opcache.jit=0', '-d', 'opcache.jit_buffer_size=0', '-d', 'pcov.directory=/', '-d', 'sys_temp_dir=' . $directory, $root . '/tests/Fixtures/string-predicates-native.php', $directory];
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
        $resultJson = file_get_contents($directory . '/result.json');
        $receipt = json_decode(file_get_contents($directory . '/evidence.json'), true, flags: JSON_THROW_ON_ERROR);
        PredicateNativeEvidence::verify($receipt, $root, $resultJson);
        // Prove each omitted producer, worker and dependency is rejected.
        foreach (array_keys($receipt['sources']) as $path) {
            $incomplete = $receipt;
            unset($incomplete['sources'][$path]);
            try {
                PredicateNativeEvidence::verify($incomplete, $root, $resultJson);
                $this->fail('Omitted predicate source was accepted: ' . $path);
            } catch (RuntimeException $error) {
                $this->assertStringContainsString('incomplete', $error->getMessage());
            }
        }
        foreach (['runtime', 'pcre', 'result', 'completed'] as $key) {
            $incomplete = $receipt;
            unset($incomplete[$key]);
            try {
                PredicateNativeEvidence::verify($incomplete, $root, $resultJson);
                $this->fail('Omitted predicate evidence was accepted: ' . $key);
            } catch (RuntimeException $error) {
                $this->assertStringContainsString('incomplete', $error->getMessage());
            }
        }
        $result = json_decode($resultJson, true, flags: JSON_THROW_ON_ERROR);
        $this->assertStringContainsString("class='odd selectable tableRow' id='12'", $result['rows'][0]);
        $this->assertStringContainsString("class='even tableRow' id='row_12'", $result['rows'][1]);
        $this->assertStringContainsString("class='probe selectable' id='ROW_12'", $result['rows'][2]);
        $this->assertStringContainsString("class='probe'>", $result['rows'][3]);
        $this->assertStringContainsString("class='nowrap highlight'", $result['cells'][0]);
        $this->assertStringContainsString("style='color:red;width:10px;'", $result['cells'][1]);
        $this->assertSame(['0_string-predicates-native' => 'ORDER BY `description` DESC'], $result['sort_update']);
        $this->assertSame('ORDER BY `description` DESC', $result['sort_get']);
        $this->assertSame(['fallback.php', 'fallback.php', '/path', 'relative.php', 'fallback.php'], $result['redirects']);
        $this->assertStringContainsString('semi-color', $result['regex']);
        $this->assertSame([false, 'Internal error', null], $result['runtime_regex_probe']);
        $this->assertSame('There was an internal error!', $result['runtime_regex']);
        $this->assertSame('Backtrack limit was exhausted!', $result['bounded_regex']);
        $this->assertSame([ini_get('pcre.backtrack_limit'), ini_get('pcre.recursion_limit')], $result['regex_limits_unchanged']);
        $this->assertSame("Unknown modifier 'z'", $result['regex_compile_after_runtime']);
        $this->assertSame('There was an internal error!', $result['regex_runtime_after_warning']);
        $this->assertTrue($result['regex_valid_with_handler']);
        $this->assertSame("Unknown modifier 'z'", $result['regex_invalid_with_handler']);
        $this->assertTrue($result['regex_handler_restored']);
        $this->assertSame([33439, bin2hex("\0\1\0cacti-monitoring-system\0"), 27], $result['native_udp']);
        $this->assertSame(['0800', '0000', 31], $result['native_icmp']);
        $this->assertSame('3b9d', $result['native_checksum']);
        $this->assertSame([true, true, true, false], $result['native_addresses']);
        $this->assertSame(['127.0.0.1', '::1', '::1'], $result['native_transports']);
        $this->assertTrue($result['native_timer']);
        $this->assertTrue($result['native_no_ping']);
        $this->assertSame([false, false, false], $result['native_missing_target']);
        $this->assertTrue($result['native_ping_error']);
        $this->assertTrue($result['native_ping_handler']);
        $this->assertSame(['ERROR', 'ERROR', 'ERROR'], $result['native_dns_rejections']);
        $this->assertSame([true, '127.0.0.1', true], $result['native_tcp_loopback']);
        $this->assertSame(['', null, true], $result['native_portable_uid']);
        $this->assertSame([false, 'down', 'Device did not respond to SNMP'], $result['native_snmp_missing_credentials']);
        $this->assertSame([4, false, ENT_COMPAT | ENT_HTML401], $result['native_ldap_defaults']);
        $this->assertSame([2, true], $result['native_ldap_enabled_options']);
        $this->assertTrue($result['native_ldap_handler']);
        $this->assertSame(['CactiErrorHandler', true], $result['native_ldap_restore']);
        $this->assertSame(array_fill(0, 3, [$result['native_ldap_expected_rejection'], '', true]), $result['native_ldap_rejections']);
        $this->assertSame('Authentication Success', $result['native_ldap_errors'][0][3]);
        $this->assertSame('Authentication Failure', $result['native_ldap_errors'][1][3]);
        $this->assertSame('No username defined', $result['native_ldap_errors'][2][3]);
        $this->assertSame('PHP LDAP not enabled', $result['native_ldap_errors'][18][3]);
        $this->assertSame('Unexpected error 100 (Ldap Error: 7) on Server (owned.test)', $result['native_ldap_errors'][19][3]);
        foreach ($result['native_ldap_errors'] as $error) {
            $this->assertSame([7, ''], [$error[1], $error[2]]);
            $this->assertNotSame('', $error[3]);
        }

        $this->assertStringContainsString('host.php?page=1', $result['pages'][0]);
        $this->assertStringContainsString('host.php?filter=x&amp;page=1', $result['pages'][1]);
        $this->assertSame(['`name`', 'name(10)', '`name`,value(10)'], $result['indexes']);
        $this->assertSame([true, false, false], $result['quoted']);
        $this->assertSame(['/base/file', '/base/file'], $result['paths']);
        $this->assertSame(0, (int) $result['core_config_cached']);
        $this->assertSame(['out/env.php' => [true, 0755], 'out/direct.php' => [true, 0755], 'lib/poller.php' => [true, 0644]], $result['replicated']);
        $this->assertSame(3, substr_count($result['lint_output'], 'No syntax errors detected'));
        if ($coverage !== null) {
            $reports = glob($directory . '/*.coverage');
            $this->assertCount(1, $reports);
            $artifact = file_get_contents($reports[0]);
            $coverageReceipt = json_decode(file_get_contents($reports[0] . '.json'), true, flags: JSON_THROW_ON_ERROR);
            PredicateNativeEvidence::verifyCoverage($coverageReceipt, $root, $resultJson, $artifact);
            foreach (['missing', 'altered'] as $corruption) {
                $invalid = $coverageReceipt;
                if ($corruption === 'missing') {
                    unset($invalid['artifact']);
                } else {
                    $invalid['artifact'] = str_repeat('0', 64);
                }
                try {
                    PredicateNativeEvidence::verifyCoverage($invalid, $root, $resultJson, $artifact);
                    $this->fail('Invalid predicate coverage artifact was accepted');
                } catch (RuntimeException $error) {
                    $this->assertStringContainsString('artifact', $error->getMessage());
                }
            }
            $coverage->merge(unserialize($artifact));
        }
    } finally {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $file) {
            $file->isLink() || !$file->isDir() ? unlink($file->getPathname()) : rmdir($file->getPathname());
        }
        rmdir($directory);
    }
});
