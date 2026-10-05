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
