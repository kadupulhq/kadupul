<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Kadupul\Platform\Infrastructure\Legacy\LegacyCommandOutput;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

require_once dirname(__DIR__) . '/Helpers/PhpSource.php';

final class LegacyCommandOutputTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/lib/functions.php');
        if (!is_string($source)) {
            self::fail('Unable to read lib/functions.php.');
        }

        eval(test_php_function_source($source, 'exec_into_array'));
    }

    public function testCommandOutputMatchesExecForBlankLinesAndNonzeroExit(): void
    {
        $command = self::phpCommand('fwrite(STDOUT, "first\\n\\nlast\\n\\n"); exit(7);');
        $expected = [];
        $status = 0;
        exec($command, $expected, $status);

        self::assertSame(7, $status);
        self::assertSame($expected, (new LegacyCommandOutput())->lines($command));
        self::assertSame($expected, exec_into_array($command));
    }

    public function testCommandWithoutOutputReturnsAnEmptyArray(): void
    {
        $command = self::phpCommand('exit(0);');

        self::assertSame([], (new LegacyCommandOutput())->lines($command));
        self::assertSame([], exec_into_array($command));
    }

    public function testArgumentArrayKeepsQuotesAndCommandSeparatorsInsideEachArgument(): void
    {
        $arguments = [
            'device" & echo injected:161',
            'community" & echo injected',
            'auth" & echo injected',
        ];
        $command = [
            PHP_BINARY,
            '-r',
            'echo json_encode(array_slice($argv, 1), JSON_THROW_ON_ERROR);',
            ...$arguments,
        ];

        self::assertSame(
            [json_encode($arguments, JSON_THROW_ON_ERROR)],
            (new LegacyCommandOutput())->linesFromArguments($command)
        );
    }

    public function testTrailingWhitespaceMatchesNativeExec(): void
    {
        $command = self::phpCommand('fwrite(STDOUT, "value  \\t\\nlast\\t \\ntrailing  \\t\\0\\n");');
        $expected = [];
        exec($command, $expected);

        self::assertSame(['value', 'last', "trailing  \t\0"], $expected);
        self::assertSame($expected, (new LegacyCommandOutput())->lines($command));
        self::assertSame($expected, exec_into_array($command));
    }

    public function testArgumentArrayOutputWithoutFinalNewlineMatchesNativeExec(): void
    {
        $payload = 'echo "first\\r\\nlast \\t";';
        $expected = [];
        exec(self::phpCommand($payload), $expected);
        self::assertSame(['first', 'last'], $expected);
        self::assertSame($expected, (new LegacyCommandOutput())->linesFromArguments([PHP_BINARY, '-r', $payload]));
    }

    public function testMissingArgumentExecutableDoesNotInvokeAShell(): void
    {
        $directory = sys_get_temp_dir() . '/command-no-shell-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($directory, 0700));
        try {
            self::assertSame([], (new LegacyCommandOutput())->linesFromArguments([
                $directory . '/missing; touch ' . $directory . '/marker',
            ]));
            self::assertFileDoesNotExist($directory . '/marker');
        } finally {
            rmdir($directory);
        }
    }

    public function testStderrOnlyCommandUnderWebSapiReturnsNoOutput(): void
    {
        [$coverage, $directory, $prelude] = $this->coverageProbe();
        $router = $directory . '/router.php';
        $autoload = var_export(dirname(__DIR__, 2) . '/include/vendor/autoload.php', true);
        $arguments = var_export([PHP_BINARY, '-r', 'fwrite(STDERR, "native-web-diagnostic"); exit(1);'], true);
        self::assertNotFalse(file_put_contents($router, '<?php ' . $prelude . 'require ' . $autoload . ';'
            . 'echo json_encode([PHP_SAPI, defined("STDERR"), '
            . '(new \\Kadupul\\Platform\\Infrastructure\\Legacy\\LegacyCommandOutput())->linesFromArguments('
            . $arguments . ')], JSON_THROW_ON_ERROR);'));
        $socket = stream_socket_server('tcp://127.0.0.1:0', $error, $message);
        self::assertIsResource($socket, $message);
        $address = stream_socket_get_name($socket, false);
        self::assertIsString($address);
        fclose($socket);
        $server = new Process([PHP_BINARY, '-d', 'pcov.directory=' . dirname(__DIR__, 2),
            '-d', 'pcov.exclude=~/(include/vendor|tests)/~', '-S', $address, $router]);
        $server->start();
        try {
            $response = false;
            $deadline = microtime(true) + 10;
            do {
                $connection = @stream_socket_client('tcp://' . $address, $error, $message, 0.1);
                if (is_resource($connection)) {
                    stream_set_timeout($connection, 5);
                    fwrite($connection, "GET / HTTP/1.0\r\nHost: localhost\r\n\r\n");
                    $response = stream_get_contents($connection);
                    fclose($connection);
                    break;
                }
                usleep(10000);
            } while ($server->isRunning() && microtime(true) < $deadline);
            self::assertIsString($response, $server->getErrorOutput());
            self::assertStringContainsString('200 OK', $response);
            self::assertSame(['cli-server', false, []], json_decode(explode("\r\n\r\n", $response, 2)[1], true, 512, JSON_THROW_ON_ERROR));
            self::assertStringContainsString('native-web-diagnostic', $server->getErrorOutput());
            $this->mergeProbeCoverage($coverage, $directory);
        } finally {
            $server->stop();
            $this->removeCoverageProbe($directory);
        }
    }

    public function testChildStderrIsForwardedLikeNativeExec(): void
    {
        [$coverage, $directory, $prelude] = $this->coverageProbe();
        $autoload = var_export(dirname(__DIR__, 2) . '/include/vendor/autoload.php', true);
        $command = var_export(self::phpCommand('fwrite(STDERR, "forwarded");'), true);
        $code = $prelude . 'require ' . $autoload . '; (new \\Kadupul\\Platform\\Infrastructure\\Legacy\\LegacyCommandOutput())->lines(' . $command . ');';
        $processCommand = escapeshellarg(PHP_BINARY) . ' -d pcov.directory=' . escapeshellarg(dirname(__DIR__, 2))
            . ' -d pcov.exclude=' . escapeshellarg('~/(include/vendor|tests)/~') . ' -r ' . escapeshellarg($code);
        try {
            $process = Process::fromShellCommandline($processCommand);
            $process->setTimeout(10);
            $process->run();

            self::assertTrue($process->isSuccessful(), $process->getErrorOutput());
            self::assertSame('forwarded', $process->getErrorOutput());
            $this->mergeProbeCoverage($coverage, $directory);
        } finally {
            $this->removeCoverageProbe($directory);
        }
    }

    public function testNativeExecFallbackKeepsWorkingWhenProcOpenIsDisabled(): void
    {
        [$coverage, $directory, $prelude] = $this->coverageProbe();
        $autoload = var_export(dirname(__DIR__, 2) . '/include/vendor/autoload.php', true);
        $payload = var_export('echo "fallback\\n";', true);
        $code = $prelude . 'require ' . $autoload . '; $command = escapeshellarg(PHP_BINARY) . " -r " . escapeshellarg(' . $payload . '); echo json_encode((new \\Kadupul\\Platform\\Infrastructure\\Legacy\\LegacyCommandOutput())->lines($command), JSON_THROW_ON_ERROR);';
        $command = escapeshellarg(PHP_BINARY) . ' -d disable_functions=proc_open'
            . ' -d pcov.directory=' . escapeshellarg(dirname(__DIR__, 2))
            . ' -d pcov.exclude=' . escapeshellarg('~/(include/vendor|tests)/~')
            . ' -r ' . escapeshellarg($code);
        try {
            $process = Process::fromShellCommandline($command);
            $process->setTimeout(10);
            $process->run();

            self::assertTrue($process->isSuccessful(), $process->getErrorOutput());
            self::assertSame('["fallback"]', $process->getOutput());
            $this->mergeProbeCoverage($coverage, $directory);
        } finally {
            $this->removeCoverageProbe($directory);
        }
    }

    public function testLegacyInstallerBootstrapWorksBeforeComposerAutoloadIsRegistered(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/lib/functions.php');
        self::assertIsString($source);

        $commandLine = escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg('echo "pre-autoload";');
        $code = test_php_function_source($source, 'exec_into_array')
            . '$commandLine = ' . var_export($commandLine, true) . ';'
            . 'echo json_encode(exec_into_array($commandLine), JSON_THROW_ON_ERROR);';
        $process = Process::fromShellCommandline(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($code));
        $process->setTimeout(10);
        $process->run();

        self::assertTrue($process->isSuccessful(), $process->getErrorOutput());
        self::assertSame('["pre-autoload"]', $process->getOutput());
    }

    private static function phpCommand(string $code): string
    {
        return escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($code);
    }

    public function testNativeSnmpCallsKeepArgumentsAndCompatibilityFlags(): void
    {
        [$coverage, $directory, $prelude] = $this->coverageProbe();
        $root = dirname(__DIR__, 2);
        $stub = '#!' . PHP_BINARY . "\n" . <<<'PHP'
<?php
file_put_contents(__DIR__ . '/arguments.jsonl', json_encode($argv, JSON_THROW_ON_ERROR) . "\n", FILE_APPEND);
if (is_file(__DIR__ . '/error-only')) {
    fwrite(STDERR, 'Timeout');
    exit(1);
}
echo str_contains(basename($argv[0]), 'walk') ? ".1.3.6.1 = 42\n" : "42\n";
PHP;
        foreach (['get', 'getnext', 'walk', 'bulkwalk'] as $binary) {
            self::assertNotFalse(file_put_contents($directory . '/snmp ' . $binary, $stub));
            self::assertTrue(chmod($directory . '/snmp ' . $binary, 0700));
        }
        $program = <<<'PHP'
require $argv[1] . '/include/vendor/autoload.php';
require $argv[1] . '/include/global_constants.php';
require $argv[1] . '/tests/Helpers/PhpSource.php';
eval(test_php_function_source(file_get_contents($argv[1] . '/lib/functions.php'), 'cacti_format_ipv6_colon'));
function cacti_sizeof($value) { return is_array($value) ? count($value) : 0; }
function cacti_escapeshellarg($value) { return escapeshellarg($value); }
function cacti_log(...$arguments) {}
function read_config_option($name) { return $GLOBALS['options'][$name] ?? ''; }
$config = ['php_snmp_support' => false, 'include_path' => $argv[1] . '/include', 'cacti_server_os' => 'unix'];
require $argv[1] . '/lib/snmp.php';
$snmp_auth_protocols = ['SHA' => 'SHA'];
$snmp_priv_protocols = ['AES' => 'AES'];
$options = ['snmp_retries' => 2, 'oid_increasing_check_disable' => ''];
foreach (['get', 'getnext', 'walk', 'bulkwalk'] as $binary) {
    $options['path_snmp' . $binary] = $argv[2] . '/snmp ' . $binary;
}
$authentication = [];
foreach ([['[None]', 'user', '', '[None]', '', '', ''],
          ['SHA', 'user', 'auth', '[None]', '', '', ''],
          ['SHA', 'user', 'auth', 'AES', 'priv', 'context', 'engine'],
          ['SHA', 'u " & | ^', 'a " & | ^', 'AES', 'p " & | ^', 'c " & | ^', 'e " & | ^']] as $case) {
    $authentication[] = [cacti_get_snmpv3_auth_arguments(...$case), cacti_get_snmpv3_auth(...$case)];
}
$targets = [snmp_format_target('192.0.2.1', 161), snmp_format_target('[2001:db8::1]', 1161), snmp_format_target('quoted host"', 161)];
$results = [];
foreach (['get', 'get_raw', 'getnext'] as $method) {
    $function = 'cacti_snmp_' . $method;
    $results[] = $function('host" name', 'community " & | ^', '.1.3.6.1', 2, '', '', '', '', '', '', 1161, 1501, 2, 'SNMP', '', SNMP_STRING_OUTPUT_HEX);
}
$results[] = cacti_snmp_get('::1', '', '.1.3.6.1', 3, 'user', 'auth', 'SHA', 'priv', 'AES', 'context', 161, 1501, 2, 'SNMP', 'engine');
foreach ([1, 2] as $version) {
    foreach ([1, 10] as $bulk) {
        foreach (['', 'on'] as $increasing) {
            $options['oid_increasing_check_disable'] = $increasing;
            $results[] = cacti_snmp_walk('192.0.2.1', 'community " & | ^', '.1.3.6.1', $version, port: 161, timeout_ms: 1501, retries: 2, bulk_walk_size: $bulk);
        }
    }
}
touch($argv[2] . '/error-only');
$emptyGet = cacti_snmp_get('192.0.2.1', 'community', '.1.3.6.1', 1);
$emptyWalk = cacti_snmp_walk('192.0.2.1', 'community', '.1.3.6.1', 1);
echo json_encode(['authentication' => $authentication, 'targets' => $targets, 'results' => $results, 'empty' => [$emptyGet, $emptyWalk]], JSON_THROW_ON_ERROR);
PHP;
        $code = $prelude . $program;
        $process = new Process([PHP_BINARY, '-d', 'pcov.directory=' . $root, '-d', 'pcov.exclude=~/(include/vendor|tests)/~', '-r', $code, $root, $directory]);
        $process->setTimeout(20);
        try {
            $process->run();
            self::assertTrue($process->isSuccessful(), $process->getErrorOutput());
            self::assertSame('TimeoutTimeout', $process->getErrorOutput());
            $result = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
            self::assertSame(['192.0.2.1:161', 'udp6:[2001:db8::1]:1161', 'quoted host":161'], $result['targets']);
            self::assertSame(['42', '42', '42', '42'], array_slice($result['results'], 0, 4));
            self::assertSame(array_fill(0, 8, [['oid' => '.1.3.6.1', 'value' => '42']]), array_slice($result['results'], 4));
            $expectedAuth = [
                ['-u', 'user', '-l', 'noAuthNoPriv'],
                ['-u', 'user', '-l', 'authNoPriv', '-a', 'SHA', '-A', 'auth'],
                ['-u', 'user', '-l', 'authPriv', '-a', 'SHA', '-A', 'auth', '-X', 'priv', '-x', 'AES', '-n', 'context', '-e', 'engine'],
                ['-u', 'u " & | ^', '-l', 'authPriv', '-a', 'SHA', '-A', 'a " & | ^', '-X', 'p " & | ^', '-x', 'AES', '-n', 'c " & | ^', '-e', 'e " & | ^'],
            ];
            foreach ($expectedAuth as $index => $arguments) {
                self::assertSame($arguments, $result['authentication'][$index][0]);
                self::assertSame($arguments, str_getcsv($result['authentication'][$index][1], ' ', "'", '\\'));
            }
            $record = file($directory . '/arguments.jsonl', FILE_IGNORE_NEW_LINES);
            self::assertIsArray($record);
            self::assertCount(14, $record);
            $calls = array_map(static fn($line) => json_decode($line, true, 512, JSON_THROW_ON_ERROR), $record);
            foreach (['get', 'get', 'getnext'] as $index => $binary) {
                self::assertSame([$directory . '/snmp ' . $binary, '-O', $index === 1 ? 'fntevx' : 'fntevUx', '-c', 'community " & | ^', '-v', '2c', '-t', '2', '-r', '2', 'host" name:1161', '.1.3.6.1'], $calls[$index]);
            }
            self::assertSame([$directory . '/snmp get', '-O', 'fntevU', ...$expectedAuth[2], '-v', '3', '-t', '2', '-r', '2', 'udp6:[::1]:161', '.1.3.6.1'], $calls[3]);
            $index = 4;
            foreach ([1, 2] as $version) {
                foreach ([1, 10] as $bulk) {
                    foreach (['', 'on'] as $increasing) {
                        $isBulk = $version === 2 && $bulk === 10;
                        $extra = $isBulk ? ['-Cr10'] : [];
                        if ($increasing === 'on') {
                            $extra[] = '-Cc';
                        }
                        self::assertSame([$directory . '/snmp ' . ($isBulk ? 'bulkwalk' : 'walk'), '-O', 'QnU', '-c', 'community " & | ^', '-v', $version === 2 ? '2c' : '1', '-t', '2', '-r', '2', ...$extra, '192.0.2.1:161', '.1.3.6.1'], $calls[$index++]);
                    }
                }
            }
            // Preserve the established empty-output get contract; only walks
            // return an array. Diagnostics must not become measurement values.
            self::assertSame(['', []], $result['empty']);
            $this->mergeProbeCoverage($coverage, $directory);
        } finally {
            $this->removeCoverageProbe($directory);
        }
    }

    /**
     * @return array{?SebastianBergmann\CodeCoverage\CodeCoverage,string,string}
     */
    private function coverageProbe(): array
    {
        $coverage = \PHPUnit\Runner\CodeCoverage::instance()->isActive()
            ? \PHPUnit\Runner\CodeCoverage::instance()->codeCoverage()
            : null;
        $directory = sys_get_temp_dir() . '/legacy-command-output-' . bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        $prelude = '';
        if ($coverage !== null) {
            $root = dirname(__DIR__, 2);
            $prelude = 'define("LEGACY_COMMAND_OUTPUT_TEST_COVERAGE", true);'
                . 'define("RRD_TEST_COVERAGE_DIRECTORY", ' . var_export($directory, true) . ');'
                . 'require ' . var_export($root . '/tests/Fixtures/rrd-process-coverage.php', true) . ';';
        }

        return [$coverage, $directory, $prelude];
    }

    private function mergeProbeCoverage(?\SebastianBergmann\CodeCoverage\CodeCoverage $coverage, string $directory): void
    {
        if ($coverage === null) {
            return;
        }

        $reports = glob($directory . '/*.coverage');
        if ($reports === []) {
            throw new \RuntimeException('The child command did not produce a coverage report.');
        }

        foreach ($reports as $report) {
            $serializedVersion = file_get_contents($report . '.version');
            self::assertIsString($serializedVersion, 'Child coverage version evidence is required.');
            self::assertSame(
                \Composer\InstalledVersions::getVersion('phpunit/php-code-coverage'),
                trim($serializedVersion),
                'Child coverage must use the parent PHPUnit code-coverage version.',
            );

            $serializedCoverage = file_get_contents($report);
            if (!is_string($serializedCoverage)) {
                throw new \RuntimeException('Unable to read child command coverage.');
            }

            $childCoverage = unserialize($serializedCoverage);
            if (!$childCoverage instanceof \SebastianBergmann\CodeCoverage\CodeCoverage) {
                throw new \RuntimeException('The child command coverage report is invalid.');
            }

            self::assertSame($coverage::class, $childCoverage::class, 'Child coverage must match the parent PHPUnit dependency version.');

            $coverage->merge($childCoverage);
        }
    }

    private function removeCoverageProbe(string $directory): void
    {
        foreach (glob($directory . '/*') as $file) {
            unlink($file);
        }
        rmdir($directory);
    }
}
