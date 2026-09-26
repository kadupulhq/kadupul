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
        $command = self::phpCommand('fwrite(STDOUT, "first\\n\\nlast\\n\\n"); fwrite(STDERR, "ignored"); exit(7);');
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
        $command = self::phpCommand('fwrite(STDOUT, "value  \\t\\nlast\\t \\n");');
        $expected = [];
        exec($command, $expected);

        self::assertSame(['value', 'last'], $expected);
        self::assertSame($expected, (new LegacyCommandOutput())->lines($command));
        self::assertSame($expected, exec_into_array($command));
    }

    public function testNativeExecFallbackKeepsWorkingWhenProcOpenIsDisabled(): void
    {
        $autoload = var_export(dirname(__DIR__, 2) . '/include/vendor/autoload.php', true);
        $payload = var_export('echo "fallback\\n";', true);
        $code = 'require ' . $autoload . '; $command = escapeshellarg(PHP_BINARY) . " -r " . escapeshellarg(' . $payload . '); echo json_encode((new \\Kadupul\\Platform\\Infrastructure\\Legacy\\LegacyCommandOutput())->lines($command), JSON_THROW_ON_ERROR);';
        $command = escapeshellarg(PHP_BINARY) . ' -d disable_functions=proc_open -r ' . escapeshellarg($code);
        $process = Process::fromShellCommandline($command);
        $process->setTimeout(10);
        $process->run();

        self::assertTrue($process->isSuccessful(), $process->getErrorOutput());
        self::assertSame('["fallback"]', $process->getOutput());
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
}
