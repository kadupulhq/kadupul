<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Doctrine\DBAL\Connection;
use Kadupul\Graphing\Application\Command\GraphCommandAccess;
use Kadupul\Graphing\Application\Command\ReplayRejectedSamples;
use Kadupul\Graphing\Application\Port\RejectedSampleReplayResult;
use Kadupul\Graphing\Infrastructure\Symfony\ReplayRejectedSamplesCommand;
use Kadupul\Graphing\Infrastructure\Symfony\ReplayRejectedSamplesLegacyArguments;
use Kadupul\IdentityAccess\Contract\AuditTrail;
use Kadupul\IdentityAccess\Contract\CollectorTopology;
use Kadupul\IdentityAccess\Infrastructure\Cli\CliConsoleAccess;
use Kadupul\Platform\Infrastructure\Legacy\InstallationVersion;
use Kadupul\Platform\Infrastructure\Symfony\Console\CliPresentation;
use Kadupul\Platform\Infrastructure\Symfony\Console\InvalidLegacyArgument;
use Kadupul\Platform\Infrastructure\Symfony\Console\LegacyRequest;
use Kadupul\Platform\Infrastructure\Symfony\Console\ResultRenderer;
use Kadupul\Tests\Fixtures\ConsoleOperatorDatabase;
use Kadupul\Tests\Fixtures\InMemoryRejectedSampleReplayer;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;

final class ReplayRejectedSamplesCommandTest extends TestCase
{
    use ConsoleOperatorDatabase;

    private string $root;
    private Connection $db;
    private CliPresentation $presentation;

    protected function setUp(): void
    {
        $this->root = $this->installationRoot('kadupul-replay-rejected-command-');
        $this->db = $this->operatorDatabase(8, 3);
        $this->presentation = new CliPresentation();
        $this->presentation->forLegacy(LegacyRequest::Run);
    }

    private function tester(InMemoryRejectedSampleReplayer $replayer): CommandTester
    {
        $access = new GraphCommandAccess(new CliConsoleAccess($this->db, $this->db), $this->createStub(CollectorTopology::class), $this->createStub(AuditTrail::class));
        $version = new InstallationVersion($this->root, $this->db, new Filesystem(), new MockClock('2031-06-01 00:00:00'));
        $command = new ReplayRejectedSamplesCommand(new ReplayRejectedSamples($access, $replayer), $version, $this->presentation, new ResultRenderer());

        return new CommandTester(new Command(null, $command));
    }

    public function testSuccessPrintsTheOriginalsLinesAfterWhatTheRunPrinted(): void
    {
        $replayer = new InMemoryRejectedSampleReplayer(printed: [['out', "library text\n"], ['err', "stderr text\n"]]);
        $tester = $this->tester($replayer);

        self::assertSame(0, $tester->execute(['--all' => true], ['capture_stderr_separately' => true]));
        self::assertSame("library text\nReplayed 5 rejected samples for all data sources\n", $tester->getDisplay());
        self::assertSame("stderr text\n", $tester->getErrorOutput());

        $quiet = new InMemoryRejectedSampleReplayer();
        $tester = $this->tester($quiet);
        self::assertSame(0, $tester->execute(['--local-data-id' => '007', '--dry-run' => true]));
        self::assertSame("Would replay 5 rejected samples for Local Data ID 7\n", $tester->getDisplay());
        self::assertSame([[null, false]], $replayer->calls);
        self::assertSame([[7, true]], $quiet->calls);
    }

    public function testAnIdPastTheIntegerRangeIsTheLargestIntegerAsInTheOriginal(): void
    {
        $replayer = new InMemoryRejectedSampleReplayer();

        self::assertSame(0, $this->tester($replayer)->execute(['--local-data-id' => '99999999999999999999']));
        self::assertSame([[PHP_INT_MAX, false]], $replayer->calls);
    }

    /** @return iterable<string, array{RejectedSampleReplayResult, string}> */
    public static function refusals(): iterable
    {
        yield 'remote' => [new RejectedSampleReplayResult(RejectedSampleReplayResult::REMOTE_COLLECTOR), "ERROR: This collector queues samples in the main database. Run replay_rejected_samples.php on the main data collector.\n"];
        yield 'queue' => [new RejectedSampleReplayResult(RejectedSampleReplayResult::UNSAFE_QUEUE, 0, 'The poller_output queue must use InnoDB.'), "ERROR: Rejected samples were not replayed. The poller_output queue must use InnoDB.\n"];
        yield 'move' => [new RejectedSampleReplayResult(RejectedSampleReplayResult::FAILED), "ERROR: Unable to replay rejected samples; nothing was moved.\n"];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('refusals')]
    public function testEachRefusalGoesToStderrAsTheOriginalWroteItAndExitsOne(RejectedSampleReplayResult $answer, string $line): void
    {
        $tester = $this->tester(new InMemoryRejectedSampleReplayer($answer));

        self::assertSame(1, $tester->execute(['--all' => true], ['capture_stderr_separately' => true]));
        self::assertSame('', $tester->getDisplay());
        self::assertSame($line, $tester->getErrorOutput());
    }

    public function testAnUnknownOutcomeIsTheFailureLine(): void
    {
        $tester = $this->tester(new InMemoryRejectedSampleReplayer(fails: true));

        self::assertSame(1, $tester->execute(['--all' => true], ['capture_stderr_separately' => true]));
        self::assertSame("ERROR: Unable to replay rejected samples; nothing was moved.\n", $tester->getErrorOutput());
    }

    public function testTheScopeErrorPrintsTheVersionAndHelpOnStdoutAndExitsOneBeforeAnythingRuns(): void
    {
        $replayer = new InMemoryRejectedSampleReplayer();
        $tester = $this->tester($replayer);

        foreach ([[], ['--all' => true, '--local-data-id' => '5'], ['--dry-run' => true]] as $arguments) {
            self::assertSame(1, $tester->execute($arguments));
            self::assertStringStartsWith("ERROR: Specify exactly one of --local-data-id=N or --all\n\nKadupul Rejected Sample Replay Utility, Version ", $tester->getDisplay());
            self::assertStringContainsString("\n\nusage: replay_rejected_samples.php --local-data-id=N | --all [--dry-run]\n\n", $tester->getDisplay());
        }
        self::assertSame([], $replayer->calls);
    }

    public function testABadIdPrintsItsErrorAndTheHelpAndRunsNothing(): void
    {
        $replayer = new InMemoryRejectedSampleReplayer();
        $tester = $this->tester($replayer);

        foreach (['0', '000', '-1', '1.5', '1e3', 'x', '', ' 5', "5\n"] as $id) {
            self::assertSame(1, $tester->execute(['--local-data-id' => $id]), json_encode($id, JSON_THROW_ON_ERROR));
            self::assertStringStartsWith("ERROR: --local-data-id requires a positive integer\n\nKadupul Rejected Sample Replay Utility", $tester->getDisplay());
        }
        self::assertSame([], $replayer->calls);
    }

    public function testTheOperatorNeedsTheDevicesRealmEvenForADryRun(): void
    {
        $this->db->executeStatement('DELETE FROM user_auth_realm WHERE realm_id = 3');
        $replayer = new InMemoryRejectedSampleReplayer();
        $tester = $this->tester($replayer);

        foreach ([['--all' => true], ['--all' => true, '--dry-run' => true]] as $arguments) {
            self::assertSame(1, $tester->execute($arguments + ['--json' => true]));
            self::assertSame('denied', json_decode($tester->getDisplay(), true)['status']);
        }
        self::assertSame([], $replayer->calls);
    }

    public function testJsonReportsTheOutcomeWithoutWhatTheRunPrinted(): void
    {
        $tester = $this->tester(new InMemoryRejectedSampleReplayer(printed: [['out', "text\n"]]));
        self::assertSame(0, $tester->execute(['--local-data-id' => '7', '--dry-run' => true, '--json' => true]));
        self::assertSame(['status' => 'ok', 'database' => 'local', 'dry_run' => true, 'local_data_id' => 7, 'samples' => 5], json_decode($tester->getDisplay(), true));

        $tester = $this->tester(new InMemoryRejectedSampleReplayer(new RejectedSampleReplayResult(RejectedSampleReplayResult::UNSAFE_QUEUE, 0, 'x')));
        self::assertSame(1, $tester->execute(['--all' => true, '--json' => true]));
        self::assertSame(['status' => 'unsafe_queue', 'database' => 'local', 'dry_run' => false, 'local_data_id' => null, 'samples' => null], json_decode($tester->getDisplay(), true));
    }

    public function testHumanOutputAndInvalidInputUnderBinConsole(): void
    {
        $this->presentation = new CliPresentation();
        $replayer = new InMemoryRejectedSampleReplayer();
        $tester = $this->tester($replayer);

        self::assertSame(0, $tester->execute(['--all' => true]));
        self::assertStringContainsString('Replayed 5 rejected samples for all data sources.', $tester->getDisplay());
        foreach ([[], ['--all' => true, '--local-data-id' => '5'], ['--local-data-id' => '0'], ['--local-data-id' => 'x']] as $arguments) {
            self::assertSame(Command::INVALID, $tester->execute($arguments), json_encode($arguments, JSON_THROW_ON_ERROR));
        }
        self::assertSame(1, $this->tester(new InMemoryRejectedSampleReplayer(new RejectedSampleReplayResult(RejectedSampleReplayResult::FAILED)))->execute(['--all' => true]));
        self::assertCount(1, $replayer->calls);
    }

    public function testAnEmptyOperatorNameIsInvalid(): void
    {
        $replayer = new InMemoryRejectedSampleReplayer();

        self::assertSame(Command::INVALID, $this->tester($replayer)->execute(['--all' => true, '--as' => '']));
        self::assertSame([], $replayer->calls);
    }

    public function testTheOldArgumentsAreReadInOrderAsTheScriptDid(): void
    {
        $arguments = new ReplayRejectedSamplesLegacyArguments();

        self::assertSame([['--local-data-id' => '9', '--all' => true, '--dry-run' => true], null], $arguments->translate(['--local-data-id=5', '--all=no', '--local-data-id=9', '--dry-run=0']));
        self::assertSame([[], null], $arguments->translate([]));
        self::assertSame(LegacyRequest::Help, $arguments->translate(['--help', '--local-data-id=x'])[1]);
        self::assertSame(LegacyRequest::Version, $arguments->translate(['--all', '-V=1', '--bogus'])[1]);
        foreach (['--local-data-id=x', '--local-data-id=0', '--local-data-id=-1', '--local-data-id=1.5', '--local-data-id=', '--local-data-id', "--local-data-id=5\n", '--local-data-id= 5', '--bogus', '=1', '', '-d', '--quiet', '1'] as $bad) {
            try {
                $arguments->translate([$bad]);
                self::fail($bad . ' was accepted.');
            } catch (InvalidLegacyArgument) {
                self::assertTrue(true);
            }
        }
        try {
            $arguments->translate(['--bogus', '--local-data-id=x']);
            self::fail('An unknown argument was accepted.');
        } catch (InvalidLegacyArgument $error) {
            self::assertSame('--bogus', $error->argument);
        }
    }

    public function testEachArgumentErrorHasItsOwnFirstLines(): void
    {
        $arguments = new ReplayRejectedSamplesLegacyArguments();

        self::assertSame(['ERROR: --local-data-id requires a positive integer', ''], $arguments->invalid('--local-data-id=x'));
        self::assertSame(['ERROR: --local-data-id requires a positive integer', ''], $arguments->invalid('--local-data-id'));
        self::assertSame(['ERROR: Invalid Parameter --local-data-idx=5', ''], $arguments->invalid('--local-data-idx=5'));
        self::assertSame(['ERROR: Invalid Parameter --bogus', ''], $arguments->invalid('--bogus'));
        self::assertSame(['ERROR: Specify exactly one of --local-data-id=N or --all', ''], $arguments->scopeError());
    }

    public function testTheHelpIsTheOriginalsWordForWord(): void
    {
        self::assertSame(['', 'usage: replay_rejected_samples.php --local-data-id=N | --all [--dry-run]', '',
            'Returns samples from poller_output_rejected to poller_output for the next',
            'poller drain.  Repair the RRDfile first or the samples will be rejected again.', '',
            '--local-data-id=N  Replay one data source',
            '--all              Replay every data source',
            '--dry-run          Report the count without moving anything', ''], (new ReplayRejectedSamplesLegacyArguments())->help());
    }
}
