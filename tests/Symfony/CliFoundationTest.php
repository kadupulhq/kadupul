<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Kadupul\Platform\Application\Command\InstallationAccessDenied;
use Kadupul\Platform\Application\Port\DatabaseTarget;
use Kadupul\Platform\Infrastructure\Doctrine\MainDatabaseNotConfigured;
use Kadupul\Platform\Infrastructure\Symfony\Console\AnalyzeDatabaseLegacyArguments;
use Kadupul\Platform\Infrastructure\Symfony\Console\AuditDatabaseLegacyArguments;
use Kadupul\Platform\Infrastructure\Symfony\Console\CliPresentation;
use Kadupul\Platform\Infrastructure\Symfony\Console\CommandResult;
use Kadupul\Platform\Infrastructure\Symfony\Console\ConvertTablesLegacyArguments;
use Kadupul\Platform\Infrastructure\Symfony\Console\InvalidLegacyArgument;
use Kadupul\Platform\Infrastructure\Symfony\Console\LegacyArguments;
use Kadupul\Platform\Infrastructure\Symfony\Console\LegacyCli;
use Kadupul\Platform\Infrastructure\Symfony\Console\LegacyRequest;
use Kadupul\Platform\Infrastructure\Symfony\Console\OutputMode;
use Kadupul\Platform\Infrastructure\Symfony\Console\ResultRenderer;
use Kadupul\Platform\Infrastructure\Symfony\Console\WidenIdColumnsLegacyArguments;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;

final class CliFoundationTest extends TestCase
{
    private function map(): LegacyArguments
    {
        return new class extends LegacyArguments {
            protected function flags(): array
            {
                return ['--local' => ['local', false], '-d' => ['debug', false], '--debug' => ['debug', false],
                    '--name' => ['name', true], '--size' => ['size', true, '/^\d+$/D'],
                    '--version' => [null, false], '-v' => [null, false], '--help' => [null, false]];
            }

            protected function special(string $flag): LegacyRequest
            {
                return str_contains($flag, 'v') ? LegacyRequest::Version : LegacyRequest::Help;
            }

            public function help(): array
            {
                return ['usage: x.php'];
            }

            public function invalid(string $argument): array
            {
                return ['ERROR: Invalid Parameter ' . $argument, ''];
            }
        };
    }

    public function testDeclaredFlagsTranslate(): void
    {
        self::assertSame([['--local' => true, '--debug' => true, '--name' => 'a=b'], null], $this->map()->translate(['--local', '-d', '--name=a=b']));
    }

    public function testSpecialModesStopTranslation(): void
    {
        self::assertSame([[], LegacyRequest::Version], $this->map()->translate(['-v', '--local']));
    }

    public function testUndeclaredFlagIsRejectedWithItsSpelling(): void
    {
        try {
            $this->map()->translate(['--local', '--bogus=1']);
            self::fail('Expected rejection');
        } catch (InvalidLegacyArgument $error) {
            self::assertSame('--bogus=1', $error->argument);
        }
    }

    public function testFlagThatNeedsAValueRejectsABareFlag(): void
    {
        $this->expectException(InvalidLegacyArgument::class);
        $this->map()->translate(['--name']);
    }

    public function testAValuePatternRejectsAValueOutsideIt(): void
    {
        self::assertSame([['--size' => '12'], null], $this->map()->translate(['--size=12']));
        try {
            $this->map()->translate(['--size=1e3']);
            self::fail('Expected rejection');
        } catch (InvalidLegacyArgument $error) {
            self::assertSame('--size=1e3', $error->argument);
        }
    }

    public function testLegacyOutputCanEndWithoutANewline(): void
    {
        $out = new BufferedOutput();
        self::assertSame(0, (new ResultRenderer())->render(new CommandResult([], ['NOTE: x', 'no newline'], 0, false), OutputMode::Legacy, $out));
        self::assertSame("NOTE: x\nno newline", $out->fetch());
    }

    public function testLegacyRequestPrintsTheVersionLineAndHelpAddsTheRest(): void
    {
        $renderer = new ResultRenderer();
        foreach ([[LegacyRequest::Version, "V 1\n"], [LegacyRequest::Help, "V 1\nusage: x.php\n"]] as [$request, $expected]) {
            $out = new BufferedOutput();
            self::assertSame(0, $renderer->legacyRequest($request, 'V 1', $this->map(), $out));
            self::assertSame($expected, $out->fetch());
        }
    }

    public function testFailuresNameOnlyAMissingMainDatabase(): void
    {
        $renderer = new ResultRenderer();
        $out = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $out);
        self::assertSame(1, $renderer->failed($io, $out, OutputMode::Json, new MainDatabaseNotConfigured(), 'X failed'));
        self::assertSame(['status' => 'failed', 'error' => 'Main database is not configured'], json_decode($out->fetch(), true));
        // The type names the problem, not the text, so a look-alike stays generic.
        self::assertSame(1, $renderer->failed($io, $out, OutputMode::Json, new \RuntimeException('Main database is not configured.'), 'X failed'));
        self::assertSame(['status' => 'failed', 'error' => 'X failed'], json_decode($out->fetch(), true));
        self::assertSame(1, $renderer->failed($io, $out, OutputMode::Legacy, new \RuntimeException("SQLSTATE[28000] 'cacti'@'db'"), 'X failed'));
        self::assertSame("ERROR: X failed\n", $out->fetch());
        self::assertSame(2, $renderer->emptyOperator($io, $out, OutputMode::Legacy));
        self::assertSame("ERROR: Invalid Parameter --as=\n", $out->fetch());
        self::assertSame(1, $renderer->denied($io, $out, OutputMode::Json));
        self::assertSame(['status' => 'denied'], json_decode($out->fetch(), true));
    }

    public function testRefusedRendersADenialAsOneAndHidesEveryOtherMessage(): void
    {
        $renderer = new ResultRenderer();
        $out = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $out);
        self::assertSame(1, $renderer->refused($io, $out, OutputMode::Legacy, new InstallationAccessDenied(7, DatabaseTarget::Local), 'X failed'));
        self::assertSame("ERROR: Unknown or unauthorized operator\n", $out->fetch());
        self::assertSame(1, $renderer->refused($io, $out, OutputMode::Json, new MainDatabaseNotConfigured(), 'X failed'));
        self::assertSame(['status' => 'failed', 'error' => 'Main database is not configured'], json_decode($out->fetch(), true));
        self::assertSame(1, $renderer->refused($io, $out, OutputMode::Legacy, new \RuntimeException("SQLSTATE[28000] 'cacti'@'db'"), 'X failed'));
        self::assertSame("ERROR: X failed\n", $out->fetch());
    }

    public function testWrittenPutsTheSharedKeysFirstAndFailsOnAnyFailure(): void
    {
        $renderer = new ResultRenderer();
        $out = new BufferedOutput();
        self::assertSame(0, $renderer->written($out, false, true, 0, ['tables' => []]));
        self::assertSame('{"status":"ok","database":"local","dry_run":true,"tables":[]}' . "\n", $out->fetch());
        self::assertSame(1, $renderer->written($out, true, false, 2, ['adjusted' => 3, 'tables' => ['a/b']]));
        self::assertSame('{"status":"partial","database":"main","dry_run":false,"adjusted":3,"tables":["a/b"]}' . "\n", $out->fetch());
    }

    public function testRenderModes(): void
    {
        $result = new CommandResult(['status' => 'failed', 'path' => 'a/b'], ['NOTE: x', 'ERROR: <y>'], 1);
        $renderer = new ResultRenderer();
        // Legacy lines are raw: a tag-like fragment must not be read as markup.
        foreach ([[OutputMode::Json, "{\"status\":\"failed\",\"path\":\"a/b\"}\n"], [OutputMode::Legacy, "NOTE: x\nERROR: <y>\n"]] as [$mode, $expected]) {
            $out = new BufferedOutput();
            self::assertSame(1, $renderer->render($result, $mode, $out));
            self::assertSame($expected, $out->fetch());
        }
    }

    public function testRendererRefusesHumanOutput(): void
    {
        $this->expectException(\LogicException::class);
        (new ResultRenderer())->render(new CommandResult([], []), OutputMode::Human, new BufferedOutput());
    }

    public function testPresentationDefaultsToHumanAndSwitchesOnlyThroughForLegacy(): void
    {
        $presentation = new CliPresentation();
        self::assertSame(OutputMode::Human, $presentation->mode);
        $presentation->forLegacy(LegacyRequest::Version);
        self::assertSame([OutputMode::Legacy, LegacyRequest::Version], [$presentation->mode, $presentation->legacy]);
    }

    public function testPresentationPropertiesCannotBeWrittenFromOutside(): void
    {
        $this->expectException(\Error::class);
        $presentation = new CliPresentation();
        $presentation->mode = OutputMode::Json;
    }

    public function testCommandRunThroughLegacyCliSeesThePresentationItSet(): void
    {
        // Kadupul\Tests\Fixtures\PresentationProbeCommand is registered only under
        // when@test in services.yaml, so the shim's own kernel needs the test
        // environment to find it. This goes through LegacyCli::run() itself, not
        // a copy of its steps, so it fails if run() ever stops sharing its
        // container between the CliPresentation write and the command it runs.
        putenv('KADUPUL_CLI_QUIET_DEPRECATION=1');
        putenv('APP_ENV=test');
        try {
            $map = get_class($this->map());
            $output = new BufferedOutput();
            $exit = LegacyCli::run('kadupul:test:presentation', $map, ['x.php'], $output);
            self::assertSame(0, $exit);
            self::assertStringContainsString('Legacy:Run', $output->fetch());
        } finally {
            putenv('APP_ENV');
            putenv('KADUPUL_CLI_QUIET_DEPRECATION');
        }
    }

    public function testLegacyCliRejectsUndeclaredFlagWithHelpAndExitOne(): void
    {
        putenv('KADUPUL_CLI_QUIET_DEPRECATION=1');
        $map = get_class($this->map());
        $output = new BufferedOutput();
        $exit = LegacyCli::run('kadupul:none', $map, ['x.php', '--bogus'], $output);
        putenv('KADUPUL_CLI_QUIET_DEPRECATION');
        self::assertSame(1, $exit);
        $text = $output->fetch();
        self::assertStringContainsString('ERROR: Invalid Parameter --bogus', $text);
        self::assertStringContainsString('usage: x.php', $text);
    }

    public function testLegacyCliAppliesTheCliCheckLimitsBeforeParsingArguments(): void
    {
        // The rejected flag returns before the kernel boots, so the limits
        // must already be in place by then, as cli_check.php had them.
        $memory = (string) ini_get('memory_limit');
        $time = (string) ini_get('max_execution_time');
        putenv('KADUPUL_CLI_QUIET_DEPRECATION=1');
        try {
            ini_set('memory_limit', '512M');
            ini_set('max_execution_time', '600');
            LegacyCli::run('kadupul:none', get_class($this->map()), ['x.php', '--bogus'], new BufferedOutput());
            self::assertSame('-1', ini_get('memory_limit'));
            // cli_check.php only raised a limit below -1 that was also at least
            // 0, which no value is, so an existing time limit is left alone.
            self::assertSame('600', ini_get('max_execution_time'));
        } finally {
            putenv('KADUPUL_CLI_QUIET_DEPRECATION');
            ini_set('max_execution_time', $time);
            ini_set('memory_limit', $memory);
        }
    }

    /**
     * Every shim's map, with the command it runs.
     *
     * @return iterable<string, array{0: class-string<LegacyArguments>, 1: string, 2?: list<string>}>
     */
    public static function shims(): iterable
    {
        yield 'analyze_database.php' => [AnalyzeDatabaseLegacyArguments::class, 'kadupul:database:analyze'];
        // convert_tables.php had its own --force, so only the audit refuses it.
        yield 'audit_database.php' => [AuditDatabaseLegacyArguments::class, 'kadupul:database:audit', ['--force']];
        yield 'convert_tables.php' => [ConvertTablesLegacyArguments::class, 'kadupul:database:convert-tables'];
        yield 'fix_mediumint.php' => [WidenIdColumnsLegacyArguments::class, 'kadupul:database:widen-id-columns'];
    }

    /**
     * @param class-string<LegacyArguments> $map
     * @param list<string> $consoleOnly further flags only the command's bin/console form takes
     */
    #[DataProvider('shims')]
    public function testEveryShimTakesTheSharedFlags(string $map, string $command, array $consoleOnly = []): void
    {
        $arguments = new $map();
        foreach (['--version', '-V', '-v'] as $flag) {
            self::assertSame(LegacyRequest::Version, $arguments->translate([$flag])[1], $flag);
        }
        foreach (['--help', '-H', '-h'] as $flag) {
            self::assertSame(LegacyRequest::Help, $arguments->translate([$flag])[1], $flag);
        }
        self::assertSame([['--as' => 'ops'], null], $arguments->translate(['--as=ops']));
        try {
            $arguments->translate(['--as=']);
            self::fail('An empty --as was accepted.');
        } catch (InvalidLegacyArgument $error) {
            self::assertSame('--as=', $error->argument);
        }
        // No original script took these; bin/console alone offers them, so the
        // shim refuses them before the kernel boots. The note names the command.
        putenv('KADUPUL_CLI_QUIET_DEPRECATION');
        foreach (['--dry-run', '--json', ...$consoleOnly] as $flag) {
            $output = new BufferedOutput();
            self::assertSame(1, LegacyCli::run($command, $map, ['x.php', $flag], $output), $flag);
            self::assertStringStartsWith('NOTE: x.php is deprecated; use bin/console ' . $command . ".\nERROR: Invalid Parameter " . $flag . "\n", $output->fetch());
        }
    }

    public function testUsageIsTheHelpPrintedAsAFailure(): void
    {
        $output = new BufferedOutput();

        $exit = (new ResultRenderer())->legacyRequest(LegacyRequest::Usage, 'Version line', new ConvertTablesLegacyArguments(), $output);

        // audit_database.php with no arguments printed its help and exited 1.
        self::assertSame(1, $exit);
        self::assertStringStartsWith("Version line\n\nusage: convert_tables.php", $output->fetch());
    }
}
