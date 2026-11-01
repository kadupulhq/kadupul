<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Doctrine\DBAL\Connection;
use Kadupul\Graphing\Application\Command\GraphCommandAccess;
use Kadupul\Graphing\Application\Command\ReorderDataQueries;
use Kadupul\Graphing\Application\Port\DataQueryIndexItem;
use Kadupul\Graphing\Infrastructure\Symfony\ReorderDataQueriesCommand;
use Kadupul\Graphing\Infrastructure\Symfony\ReorderDataQueriesLegacyArguments;
use Kadupul\IdentityAccess\Contract\AuditTrail;
use Kadupul\IdentityAccess\Contract\CollectorTopology;
use Kadupul\IdentityAccess\Infrastructure\Cli\CliConsoleAccess;
use Kadupul\Platform\Infrastructure\Legacy\InstallationVersion;
use Kadupul\Platform\Infrastructure\Symfony\Console\CliPresentation;
use Kadupul\Platform\Infrastructure\Symfony\Console\InvalidLegacyArgument;
use Kadupul\Platform\Infrastructure\Symfony\Console\LegacyRequest;
use Kadupul\Platform\Infrastructure\Symfony\Console\ResultRenderer;
use Kadupul\Tests\Fixtures\ConsoleOperatorDatabase;
use Kadupul\Tests\Fixtures\InMemoryDataQueryIndexes;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;

final class ReorderDataQueriesCommandTest extends TestCase
{
    use ConsoleOperatorDatabase;

    private const string WARNING = "WARNING: Do not interrupt this script.  Reordering can take quite some time\n";

    private string $root;
    private Connection $db;
    private CliPresentation $presentation;

    protected function setUp(): void
    {
        $this->root = $this->installationRoot('kadupul-reorder-data-query-command-');
        // User 1, admin, holds Console Access (8) and Devices (3).
        $this->db = $this->operatorDatabase(8, 3);
        $this->presentation = new CliPresentation();
    }

    private function tester(InMemoryDataQueryIndexes $indexes, bool $collector = false): CommandTester
    {
        $topology = $this->createStub(CollectorTopology::class);
        $topology->method('isRemoteCollector')->willReturn($collector);
        $access = new GraphCommandAccess(new CliConsoleAccess($this->db, $this->db), $topology, $this->createStub(AuditTrail::class));
        $version = new InstallationVersion($this->root, $this->db, new Filesystem(), new MockClock('2031-06-01 00:00:00'));
        $command = new ReorderDataQueriesCommand(new ReorderDataQueries($access, $indexes, $indexes), $version, $this->presentation, new ResultRenderer());

        return new CommandTester(new Command(null, $command));
    }

    private function versionLine(): string
    {
        return 'Kadupul Reorder Data Query Utility, Version 1.3.0 (DB: 1.3.0), Copyright (C) 2004-2031 The Cacti Group';
    }

    private function help(): string
    {
        return $this->versionLine() . "\n\n"
            . "usage: reorder_data_query.php --host-id=[id|all] --qid=[query_id|all] [--debug|-d]\n\n"
            . "A utility to Re-order Kadupul Data Queries for a Device or system in batch mode.\n\n"
            . "Required:\n"
            . "    --host-id=N|all - The Device id to be reindexed, or 'all' for every Device.\n"
            . "    --qid=query_id|all - The query id to reindex, or 'all' for every query.\n\n"
            . "Optional:\n"
            . "    --debug | -d   - Display verbose output during execution\n\n";
    }

    private static function two(?int $stopsAt = null): InMemoryDataQueryIndexes
    {
        return new InMemoryDataQueryIndexes([new DataQueryIndexItem(7, 11, '3', 40, '4'), new DataQueryIndexItem(8, 12, 'eth0 ', 41, null)], $stopsAt);
    }

    public function testLegacyPrintsOneDotPerItemWithoutATrailingNewline(): void
    {
        $this->presentation->forLegacy(LegacyRequest::Run);
        $tester = $this->tester(self::two());

        self::assertSame(0, $tester->execute(['--host-id' => 'all', '--qid' => 'all']));
        self::assertSame(self::WARNING . '..', $tester->getDisplay());
    }

    public function testLegacyWithNothingSelectedPrintsOnlyTheWarning(): void
    {
        $this->presentation->forLegacy(LegacyRequest::Run);
        $tester = $this->tester(new InMemoryDataQueryIndexes([]));

        self::assertSame(0, $tester->execute(['--qid' => '5']));
        self::assertSame(self::WARNING, $tester->getDisplay());
    }

    public function testLegacyDebugCountsThenDescribesEachItemAsTheScriptDid(): void
    {
        $this->presentation->forLegacy(LegacyRequest::Run);
        $tester = $this->tester(self::two());

        self::assertSame(0, $tester->execute(['--host-id' => 'all', '--qid' => 'all', '--debug' => true]));
        // Note the missing quote before the first index: the script printed it that way.
        self::assertSame(self::WARNING . "DEBUG: There are '2' data query index items to run\n"
            . "DEBUG: Data Query #'1' host: '7' SNMP Query Id: '11' Index: 3' Index On: ifName11\n"
            . "DEBUG: Data Query #'2' host: '8' SNMP Query Id: '12' Index: eth0 ' Index On: ifName12\n", $tester->getDisplay());
    }

    public function testASortFieldThatCannotBeDeterminedPrintsItsDotThenTheErrorAndExitsOne(): void
    {
        $this->presentation->forLegacy(LegacyRequest::Run);
        $tester = $this->tester(self::two(1));

        self::assertSame(1, $tester->execute(['--host-id' => 'all', '--qid' => 'all']));
        self::assertSame(self::WARNING . '..' . "ERROR: Unable to determine sort field for Device[8] and DQ[12].\n", $tester->getDisplay());
    }

    public function testTheHostDefaultsToAllWhenOnlyTheQueryIsGiven(): void
    {
        $this->presentation->forLegacy(LegacyRequest::Run);
        $indexes = self::two();
        $this->tester($indexes)->execute(['--qid' => '11']);

        self::assertSame([null, 11], [$indexes->selections[0]->hostId, $indexes->selections[0]->queryId]);
    }

    public function testLegacyRefusesABadSelectorOnStderrWithoutTouchingAnything(): void
    {
        $this->presentation->forLegacy(LegacyRequest::Run);
        $indexes = self::two();
        $tester = $this->tester($indexes);

        self::assertSame(1, $tester->execute(['--host-id' => '0', '--qid' => 'all']));
        self::assertSame("ERROR: --host-id must be a positive integer or 'all'.\n", $tester->getDisplay());
        self::assertSame(1, $tester->execute(['--host-id' => 'all']));
        self::assertSame("ERROR: --qid must be a positive query ID or 'all'.\n", $tester->getDisplay());
        self::assertSame(1, $tester->execute(['--debug' => true]));
        self::assertSame([], $indexes->selections);
    }

    public function testLegacyWithNoArgumentsPrintsTheErrorAndTheHelp(): void
    {
        $this->presentation->forLegacy(LegacyRequest::Run);
        $tester = $this->tester(self::two());

        self::assertSame(1, $tester->execute([]));
        self::assertSame("ERROR: You must supply input parameters\n\n" . $this->help(), $tester->getDisplay());
    }

    public function testLegacyHelpAndVersionExitZero(): void
    {
        $tester = $this->tester(self::two());
        $this->presentation->forLegacy(LegacyRequest::Help);
        self::assertSame(0, $tester->execute([]));
        self::assertSame($this->help(), $tester->getDisplay());

        $this->presentation->forLegacy(LegacyRequest::Version);
        self::assertSame(0, $tester->execute([]));
        self::assertSame($this->versionLine() . "\n", $tester->getDisplay());
    }

    public function testARemoteCollectorIsRefusedBeforeAnythingElseHelpIncluded(): void
    {
        foreach ([LegacyRequest::Run, LegacyRequest::Help] as $request) {
            $this->presentation->forLegacy($request);
            $indexes = self::two();
            $tester = $this->tester($indexes, collector: true);

            self::assertSame(1, $tester->execute(['--host-id' => 'all', '--qid' => 'all']));
            self::assertSame("FATAL: This utility is designed for the main Data Collector only\n", $tester->getDisplay());
            self::assertSame([[], []], [$indexes->selections, $indexes->runs]);
        }
    }

    public function testJsonListsTheCountsAndNamesWhereARunStopped(): void
    {
        $tester = $this->tester(self::two());
        self::assertSame(0, $tester->execute(['--host-id' => 'all', '--qid' => 'all', '--json' => true]));
        self::assertSame(['status' => 'ok', 'database' => 'local', 'dry_run' => false, 'selected' => 2, 'reordered' => 2], json_decode($tester->getDisplay(), true));

        $short = $this->tester(self::two(1));
        self::assertSame(1, $short->execute(['--host-id' => 'all', '--qid' => 'all', '--json' => true]));
        $json = json_decode($short->getDisplay(), true);
        self::assertSame(['partial', 1, ['host_id' => 8, 'snmp_query_id' => 12]], [$json['status'], $json['reordered'], $json['stopped_at']]);
    }

    public function testADryRunReordersNothing(): void
    {
        $indexes = self::two();
        $tester = $this->tester($indexes);

        self::assertSame(0, $tester->execute(['--host-id' => 'all', '--qid' => 'all', '--dry-run' => true, '--json' => true]));
        self::assertSame([], $indexes->runs);
        self::assertTrue(json_decode($tester->getDisplay(), true)['dry_run']);
    }

    public function testAnInvalidSelectorIsInvalidInputUnderBinConsole(): void
    {
        $tester = $this->tester(self::two());

        self::assertSame(Command::INVALID, $tester->execute(['--host-id' => 'x', '--qid' => 'all']));
        self::assertStringContainsString("--host-id must be a positive integer or 'all'.", $tester->getDisplay());
    }

    public function testAnOperatorWithoutRealm3IsDenied(): void
    {
        $this->db->executeStatement('DELETE FROM user_auth_realm WHERE realm_id = 3');
        $tester = $this->tester(self::two());

        self::assertSame(1, $tester->execute(['--host-id' => 'all', '--qid' => 'all', '--json' => true]));
        self::assertSame('denied', json_decode($tester->getDisplay(), true)['status']);
    }

    public function testTheLegacyMapKeepsEveryFlagAndEmptyValueTheOriginalAccepted(): void
    {
        $map = new ReorderDataQueriesLegacyArguments();

        self::assertSame([['--host-id' => '4', '--qid' => 'x=y', '--debug' => true], null], $map->translate(['--id=4', '-qid=x=y', '-d']));
        self::assertSame([['--host-id' => '', '--qid' => ''], null], $map->translate(['--host-id', '--qid=']));
        self::assertSame([[], LegacyRequest::Help], $map->translate(['--debug', '-h']));
        self::assertSame([[], LegacyRequest::Version], $map->translate(['-V']));
        $this->expectException(InvalidLegacyArgument::class);
        $map->translate(['--host-id=1', '=2']);
    }
}
