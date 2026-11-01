<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Doctrine\DBAL\Connection;
use Kadupul\Graphing\Application\Command\EmptyPollerOutput;
use Kadupul\Graphing\Application\Command\GraphCommandAccess;
use Kadupul\Graphing\Application\Port\PollerOutputDrainResult;
use Kadupul\Graphing\Infrastructure\Symfony\EmptyPollerOutputCommand;
use Kadupul\Graphing\Infrastructure\Symfony\EmptyPollerOutputLegacyArguments;
use Kadupul\IdentityAccess\Contract\AuditTrail;
use Kadupul\IdentityAccess\Contract\CollectorTopology;
use Kadupul\IdentityAccess\Infrastructure\Cli\CliConsoleAccess;
use Kadupul\Platform\Infrastructure\Legacy\InstallationVersion;
use Kadupul\Platform\Infrastructure\Symfony\Console\CliPresentation;
use Kadupul\Platform\Infrastructure\Symfony\Console\InvalidLegacyArgument;
use Kadupul\Platform\Infrastructure\Symfony\Console\LegacyRequest;
use Kadupul\Platform\Infrastructure\Symfony\Console\ResultRenderer;
use Kadupul\Tests\Fixtures\ConsoleOperatorDatabase;
use Kadupul\Tests\Fixtures\InMemoryPollerOutputDrain;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;

final class EmptyPollerOutputCommandTest extends TestCase
{
    use ConsoleOperatorDatabase;

    private string $root;
    private Connection $db;
    private CliPresentation $presentation;

    protected function setUp(): void
    {
        $this->root = $this->installationRoot('kadupul-empty-poller-output-command-');
        $this->db = $this->operatorDatabase(8, 3);
        $this->presentation = new CliPresentation();
        $this->presentation->forLegacy(LegacyRequest::Run);
    }

    private function tester(InMemoryPollerOutputDrain $drain): CommandTester
    {
        $access = new GraphCommandAccess(new CliConsoleAccess($this->db, $this->db), $this->createStub(CollectorTopology::class), $this->createStub(AuditTrail::class));
        $version = new InstallationVersion($this->root, $this->db, new Filesystem(), new MockClock('2031-06-01 00:00:00'));
        $command = new EmptyPollerOutputCommand(new EmptyPollerOutput($access, $drain), $version, $this->presentation, new ResultRenderer());

        return new CommandTester(new Command(null, $command));
    }

    public function testSuccessPrintsTheUpdateCountOnStdoutAfterWhatThePassPrinted(): void
    {
        $drain = new InMemoryPollerOutputDrain(printed: [['out', "OK u:0.01 s:0.00 r:0.00\n"], ['err', "stderr text\n"]]);
        $tester = $this->tester($drain);

        self::assertSame(0, $tester->execute([], ['capture_stderr_separately' => true]));
        self::assertSame("OK u:0.01 s:0.00 r:0.00\nThere were 3 RRD updates made this pass\n", $tester->getDisplay());
        self::assertSame("stderr text\n", $tester->getErrorOutput());
    }

    /** @return iterable<string, array{string, string}> */
    public static function refusals(): iterable
    {
        yield 'rrdtool' => [PollerOutputDrainResult::RRD_UNAVAILABLE, "ERROR: RRD initialization failed; queued samples retained for retry.\n"];
        yield 'count' => [PollerOutputDrainResult::COUNT_UNAVAILABLE, "ERROR: Poller output count is unavailable; samples retained for retry.\n"];
        yield 'progress' => [PollerOutputDrainResult::NO_PROGRESS, "ERROR: Poller output made no progress; remaining samples retained for retry.\n"];
        yield 'worker' => [PollerOutputDrainResult::FAILED, "ERROR: Poller output could not be processed; unprocessed samples remain queued.\n"];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('refusals')]
    public function testEachRefusalGoesToStderrAsTheOriginalWroteItAndExitsOne(string $status, string $line): void
    {
        $tester = $this->tester(new InMemoryPollerOutputDrain(new PollerOutputDrainResult($status)));

        self::assertSame(1, $tester->execute([], ['capture_stderr_separately' => true]));
        self::assertSame('', $tester->getDisplay());
        self::assertSame($line, $tester->getErrorOutput());
    }

    public function testAnUnknownOutcomeIsAFailureLine(): void
    {
        $tester = $this->tester(new InMemoryPollerOutputDrain(fails: true));

        self::assertSame(1, $tester->execute([], ['capture_stderr_separately' => true]));
        self::assertSame("ERROR: Poller output could not be processed; unprocessed samples remain queued.\n", $tester->getErrorOutput());
    }

    public function testTheOperatorNeedsTheDevicesRealm(): void
    {
        $this->db->executeStatement('DELETE FROM user_auth_realm WHERE realm_id = 3');
        $drain = new InMemoryPollerOutputDrain();
        $tester = $this->tester($drain);

        self::assertSame(1, $tester->execute(['--json' => true]));
        self::assertSame('denied', json_decode($tester->getDisplay(), true)['status']);
        self::assertSame(0, $drain->drained);
    }

    public function testJsonReportsTheOutcomeWithoutWhatThePassPrinted(): void
    {
        $tester = $this->tester(new InMemoryPollerOutputDrain(printed: [['out', "OK u:0.01\n"]]));
        self::assertSame(0, $tester->execute(['--json' => true]));
        self::assertSame(['status' => 'ok', 'database' => 'local', 'updates' => 3], json_decode($tester->getDisplay(), true));

        $tester = $this->tester(new InMemoryPollerOutputDrain(new PollerOutputDrainResult(PollerOutputDrainResult::NO_PROGRESS)));
        self::assertSame(1, $tester->execute(['--json' => true]));
        self::assertSame(['status' => 'no_progress', 'database' => 'local', 'updates' => null], json_decode($tester->getDisplay(), true));
    }

    public function testHumanOutputUnderBinConsole(): void
    {
        $this->presentation = new CliPresentation();
        $tester = $this->tester(new InMemoryPollerOutputDrain(printed: [['out', "OK u:0.01\n"]]));
        self::assertSame(0, $tester->execute([]));
        self::assertStringContainsString('There were 3 RRD updates made this pass.', $tester->getDisplay());
        self::assertStringNotContainsString('OK u:0.01', $tester->getDisplay());

        $tester = $this->tester(new InMemoryPollerOutputDrain(new PollerOutputDrainResult(PollerOutputDrainResult::RRD_UNAVAILABLE)));
        self::assertSame(1, $tester->execute([]));
        self::assertStringContainsString('RRD initialization failed', $tester->getDisplay());
    }

    public function testAnEmptyOperatorNameIsInvalid(): void
    {
        $drain = new InMemoryPollerOutputDrain();

        self::assertSame(Command::INVALID, $this->tester($drain)->execute(['--as' => '']));
        self::assertSame(0, $drain->drained);
    }

    public function testOnlyTheVersionAndHelpFlagsAreReadAsTheScriptDid(): void
    {
        $arguments = new EmptyPollerOutputLegacyArguments();

        self::assertSame([[], null], $arguments->translate([]));
        self::assertSame(LegacyRequest::Version, $arguments->translate(['-v', '--bogus'])[1]);
        self::assertSame(LegacyRequest::Version, $arguments->translate(['--version=3'])[1]);
        self::assertSame(LegacyRequest::Help, $arguments->translate(['-H=1', '--bogus'])[1]);
        self::assertSame(['ERROR: Invalid Parameter --bogus=1', ''], $arguments->invalid('--bogus=1'));
        foreach (['-d', '--quiet', '--bogus', '=1', '', '1'] as $unknown) {
            try {
                $arguments->translate([$unknown]);
                self::fail($unknown . ' was accepted.');
            } catch (InvalidLegacyArgument) {
                self::assertTrue(true);
            }
        }
        try {
            $arguments->translate(['--bogus', '--help']);
            self::fail('An unknown argument before help was accepted.');
        } catch (InvalidLegacyArgument $error) {
            self::assertSame('--bogus', $error->argument);
        }
    }

    public function testTheHelpIsTheOriginalsWordForWord(): void
    {
        self::assertSame(['', 'usage: poller_output_empty.php', '', 'A utility to process the poller output table.  This tool is deprecated but should work.', ''], (new EmptyPollerOutputLegacyArguments())->help());
    }
}
