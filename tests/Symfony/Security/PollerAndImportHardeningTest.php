<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Kadupul\Tests\Security;

use PHPUnit\Framework\TestCase;

final class PollerAndImportHardeningTest extends TestCase
{
    public function testVerifyDataInputWhitelistFailsClosedOnMissingOrCorruptFileAndUsesStrictComparison(): void
    {
        $root = dirname(__DIR__, 3);
        $dir = sys_get_temp_dir() . '/kadupul-wl-test-' . bin2hex(random_bytes(6));
        mkdir($dir, 0700, true);
        $badFile = $dir . '/corrupt.json';
        $goodFile = $dir . '/good.json';
        file_put_contents($badFile, '{not valid json');
        file_put_contents($goodFile, json_encode(['h1' => '0e12345'], JSON_THROW_ON_ERROR));

        $script = <<<'PHP'
<?php
$root = $argv[1];
$dir = $argv[2];
$config = ['input_whitelist' => $dir . '/missing.json'];
function cacti_log($msg, ...$a) {}
require_once $root . '/tests/Helpers/PhpSource.php';
eval(test_php_function_source(file_get_contents($root . '/lib/template.php'), 'verify_data_input_whitelist'));
$r1 = verify_data_input_whitelist('h1', 'cmd');
$config['input_whitelist'] = $dir . '/corrupt.json';
$r2 = verify_data_input_whitelist('h1', 'cmd');
$config['input_whitelist'] = $dir . '/good.json';
$r3 = verify_data_input_whitelist('h1', '0e99999');
$r4 = verify_data_input_whitelist('h1', '0e12345');
echo json_encode([$r1, $r2, $r3, $r4]);
PHP;
        $tmpScript = $dir . '/run.php';
        file_put_contents($tmpScript, $script);

        try {
            $process = proc_open([PHP_BINARY, $tmpScript, $root, $dir], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            $this->assertIsResource($process);
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $this->assertSame(0, proc_close($process), (string) $stderr);
            $this->assertSame([false, false, false, true], json_decode((string) $stdout, true, 512, JSON_THROW_ON_ERROR));
        } finally {
            foreach (glob($dir . '/*') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($dir);
        }
    }

    public function testGetScriptQueryPathIsolatesLiteralSnmpIndexFromTemplateSubstitution(): void
    {
        $root = dirname(__DIR__, 3);
        $script = <<<'PHP'
<?php
$root = $argv[1];
$config = ['library_path' => $root . '/lib', 'base_path' => $root, 'cacti_server_os' => 'unix'];
function cacti_log($msg, ...$a) {}
function cacti_escapeshellarg($s, $q = true, $w = false, $c = false) { return escapeshellarg((string) $s); }
function cacti_escapeshellcmd($s) { return escapeshellcmd((string) $s); }
function substitute_script_query_path($p) { return $p; }
function substitute_host_data($s, $l, $r, $h) { return str_replace('|host_snmp_community|', 'SECRET_COMM', (string) $s); }
require_once $root . '/tests/Helpers/PhpSource.php';
$body = str_replace("include_once(\$config['library_path'] . '/variables.php');", '', test_php_function_source(file_get_contents($root . '/lib/data_query.php'), 'get_script_query_path'));
eval($body);
$out = get_script_query_path('get used', '/srv/scripts/disk.php', 7, ['foo" |host_snmp_community| bar']);
echo $out;
PHP;
        $tmpScript = sys_get_temp_dir() . '/kadupul-sqp-' . bin2hex(random_bytes(6)) . '.php';
        file_put_contents($tmpScript, $script);

        try {
            $process = proc_open([PHP_BINARY, $tmpScript, $root], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            $this->assertIsResource($process);
            $stdout = (string) stream_get_contents($pipes[1]);
            $stderr = (string) stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $this->assertSame(0, proc_close($process), $stderr);
            $this->assertStringNotContainsString('SECRET_COMM', $stdout);
            $this->assertStringContainsString('|host_snmp_community|', $stdout);
        } finally {
            @unlink($tmpScript);
        }
    }

    public function testPollerCommandsAndImportHardeningChecksArePresent(): void
    {
        $root = dirname(__DIR__, 3);
        $importSource = file_get_contents($root . '/lib/import.php');
        $pollerSource = file_get_contents($root . '/lib/poller.php');
        $commandsSource = file_get_contents($root . '/poller_commands.php');
        $dataSourcesSource = file_get_contents($root . '/data_sources.php');

        $this->assertIsString($importSource);
        $this->assertIsString($pollerSource);
        $this->assertIsString($commandsSource);
        $this->assertIsString($dataSourcesSource);

        $this->assertStringContainsString('DataInputState::SYSTEM', $importSource);
        $this->assertStringContainsString('is_realm_allowed(2)', $importSource);
        $this->assertStringContainsString('TemplateEditableColumns::isSuggestedDataSourceFieldAllowed', $importSource);
        $this->assertStringContainsString('IpAddressPolicy::isSafeRemoteHostSyntax', $pollerSource);
        $this->assertStringContainsString('name != "csrf_secret"', $pollerSource);
        $this->assertStringContainsString('/\\A[1-9][0-9]*\\z/D', $commandsSource);
        $this->assertStringContainsString("str_ends_with(strtolower(\$path), '.rrd')", $dataSourcesSource);
    }
}
