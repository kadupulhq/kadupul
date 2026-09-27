<?php

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

    public function testTrailingWhitespaceMatchesNativeExec(): void
    {
        $command = self::phpCommand('fwrite(STDOUT, "value  \\t\\nlast\\t \\ntrailing  \\t\\0\\n");');
        $expected = [];
        exec($command, $expected);

        self::assertSame(['value', 'last', "trailing  \t\0"], $expected);
        self::assertSame($expected, (new LegacyCommandOutput())->lines($command));
        self::assertSame($expected, exec_into_array($command));
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
            $serializedCoverage = file_get_contents($report);
            if (!is_string($serializedCoverage)) {
                throw new \RuntimeException('Unable to read child command coverage.');
            }

            $childCoverage = unserialize($serializedCoverage);
            if (!$childCoverage instanceof \SebastianBergmann\CodeCoverage\CodeCoverage) {
                throw new \RuntimeException('The child command coverage report is invalid.');
            }

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
