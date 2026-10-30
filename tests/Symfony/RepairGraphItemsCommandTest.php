<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Doctrine\DBAL\Connection;
use Kadupul\Graphing\Application\Command\GraphCommandAccess;
use Kadupul\Graphing\Application\Command\RepairGraphItems;
use Kadupul\Graphing\Application\Port\RepairGraph;
use Kadupul\Graphing\Application\Port\RepairRrdTarget;
use Kadupul\Graphing\Application\Port\WrongItem;
use Kadupul\Graphing\Infrastructure\Symfony\RepairGraphItemsCommand;
use Kadupul\Graphing\Infrastructure\Symfony\RepairGraphItemsLegacyArguments;
use Kadupul\IdentityAccess\Contract\AuditTrail;
use Kadupul\IdentityAccess\Contract\CollectorTopology;
use Kadupul\IdentityAccess\Infrastructure\Cli\CliConsoleAccess;
use Kadupul\Platform\Infrastructure\Legacy\InstallationVersion;
use Kadupul\Platform\Infrastructure\Symfony\Console\CliPresentation;
use Kadupul\Platform\Infrastructure\Symfony\Console\InvalidLegacyArgument;
use Kadupul\Platform\Infrastructure\Symfony\Console\LegacyRequest;
use Kadupul\Platform\Infrastructure\Symfony\Console\ResultRenderer;
use Kadupul\Tests\Fixtures\ConsoleOperatorDatabase;
use Kadupul\Tests\Fixtures\InMemoryGraphItemRepairs;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;

final class RepairGraphItemsCommandTest extends TestCase
{
    use ConsoleOperatorDatabase;

    private string $root;
    private Connection $db;
    private CliPresentation $presentation;

    protected function setUp(): void
    {
        $this->root = $this->installationRoot('kadupul-repair-items-command-');
        $this->db = $this->operatorDatabase(8, 5);
        $this->presentation = new CliPresentation();
    }

    private function repairs(): InMemoryGraphItemRepairs
    {
        return new InMemoryGraphItemRepairs(
            [new RepairGraph(10, 1), new RepairGraph(20, 2)],
            [1 => new RepairRrdTarget(100, 500), 2 => new RepairRrdTarget(200, null)],
            [10 => [new WrongItem(7, 90), new WrongItem(8, 91)]],
        );
    }

    private function tester(InMemoryGraphItemRepairs $repairs): CommandTester
    {
        $access = new GraphCommandAccess(new CliConsoleAccess($this->db, $this->db), $this->createStub(CollectorTopology::class), $this->createStub(AuditTrail::class));
        $version = new InstallationVersion($this->root, $this->db, new Filesystem(), new MockClock('2031-06-01 00:00:00'));
        $command = new RepairGraphItemsCommand(new RepairGraphItems($access, $repairs), $version, $this->presentation, new ResultRenderer());

        return new CommandTester(new Command(null, $command));
    }

    private function help(): string
    {
        return "usage: repair_graphs.php [--host-id=ID] --data-template-id=[ID]\n\t--graph-template-id=[ID] [--show-sql] [--execute]\n\n"
            . "Kadupul utility for repairing graph<->datasource relationship via a command line interface.\n\n"
            . "--execute - Perform the repair\n--show-sql - Show SQL lines for the repair (optional)\n"
            . "--host-id=id - The host_id to repair or leave empty to process all hosts\n"
            . "--data-template-id=id - The numerical ID of the data template to be fixed\n"
            . "--graph-template-id=id - The numerical ID of the graph template to be fixed\n";
    }

    /** @return array<string, mixed> */
    private static function args(array $extra = []): array
    {
        return ['--graph-template-id' => '5', '--data-template-id' => '6'] + $extra;
    }

    public function testLegacyExecutePrintsTheRepairLinesAndRepoints(): void
    {
        $this->presentation->forLegacy(LegacyRequest::Run);
        $repairs = $this->repairs();
        $tester = $this->tester($repairs);

        self::assertSame(0, $tester->execute(self::args(['--execute' => true])));
        self::assertSame("NOTE: Repairing Graphs\n\nCorrupted graphs:\nHost 1, graph 10, graph item 7,8, task_item_id 90,91->500\nCould not get correct rrd id for datasource=200\n", $tester->getDisplay());
        self::assertSame(['10>500:7,8'], $repairs->repoints);
    }

    public function testLegacyShowSqlPrintsTheStatementWithoutTheHeadingAndChangesNothing(): void
    {
        $this->presentation->forLegacy(LegacyRequest::Run);
        $repairs = $this->repairs();
        $tester = $this->tester($repairs);

        self::assertSame(0, $tester->execute(self::args(['--show-sql' => true])));
        self::assertSame("NOTE: Performing Check of Graphs\nHost 1, graph 10, graph item 7,8, task_item_id 90,91->500\n"
            . "UPDATE graph_templates_item SET task_item_id=500 WHERE task_item_id!=500 and graph_template_id=5 and local_graph_id=10 and id in (7,8);\n"
            . "Could not get correct rrd id for datasource=200\n", $tester->getDisplay());
        self::assertSame([], $repairs->repoints);
    }

    public function testLegacyWithNoModePrintsTheHelpAndExitsOne(): void
    {
        $this->presentation->forLegacy(LegacyRequest::Run);
        $tester = $this->tester($this->repairs());

        self::assertSame(1, $tester->execute(self::args()));
        self::assertSame($this->help(), $tester->getDisplay());
    }

    public function testLegacyNamesTheMissingTemplateAndChecksTheDataTemplateFirst(): void
    {
        $this->presentation->forLegacy(LegacyRequest::Run);
        $tester = $this->tester($this->repairs());

        self::assertSame(1, $tester->execute(['--execute' => true]));
        self::assertSame("ERROR: You must supply a valid data-template-id!\n", $tester->getDisplay());
        self::assertSame(1, $tester->execute(['--execute' => true, '--data-template-id' => '6']));
        self::assertSame("ERROR: You must supply a valid graph-template-id!\n", $tester->getDisplay());
    }

    public function testLegacyHelpAndUsageHaveNoVersionLineButVersionStillPrintsIt(): void
    {
        $tester = $this->tester($this->repairs());
        $this->presentation->forLegacy(LegacyRequest::Help);
        self::assertSame(0, $tester->execute([]));
        self::assertSame($this->help(), $tester->getDisplay());

        $this->presentation->forLegacy(LegacyRequest::Usage);
        self::assertSame(1, $tester->execute([]));
        self::assertSame($this->help(), $tester->getDisplay());

        $this->presentation->forLegacy(LegacyRequest::Version);
        self::assertSame(0, $tester->execute([]));
        self::assertSame("Kadupul Graph Repair Tool, Version 1.3.0 (DB: 1.3.0), Copyright (C) 2004-2031 The Cacti Group\n", $tester->getDisplay());
    }

    public function testJsonListsTheEntriesAndTheSqlOnlyWhenAsked(): void
    {
        $tester = $this->tester($this->repairs());
        self::assertSame(0, $tester->execute(self::args(['--json' => true, '--show-sql' => true])));
        $json = json_decode($tester->getDisplay(), true);

        self::assertSame(['ok', false, 2, 1], [$json['status'], $json['executed'], $json['checked'], $json['repointed']]);
        self::assertSame([7, 8], $json['entries'][0]['items']);
        self::assertStringStartsWith('UPDATE graph_templates_item', $json['entries'][0]['sql']);
        self::assertSame(['data_source_id' => 200, 'problem' => 'no-rrd-item'], $json['entries'][1]);

        $tester->execute(self::args(['--json' => true]));
        self::assertNull(json_decode($tester->getDisplay(), true)['entries'][0]['sql']);
    }

    public function testBinConsoleReportsWithoutAModeAndRejectsABadId(): void
    {
        $repairs = $this->repairs();
        $tester = $this->tester($repairs);

        self::assertSame(0, $tester->execute(self::args()));
        self::assertStringContainsString('Would repoint 1 of 2 graph(s).', $tester->getDisplay());
        self::assertSame([], $repairs->repoints);
        self::assertSame(Command::INVALID, $tester->execute(self::args(['--host-id' => '1.5'])));
        self::assertSame(Command::INVALID, $tester->execute(['--graph-template-id' => '5']));
    }

    public function testAnOperatorWithoutRealm5IsDenied(): void
    {
        $this->db->executeStatement('DELETE FROM user_auth_realm WHERE realm_id = 5');
        $tester = $this->tester($this->repairs());

        self::assertSame(1, $tester->execute(self::args(['--json' => true, '--execute' => true])));
        self::assertSame('denied', json_decode($tester->getDisplay(), true)['status']);
    }

    public function testTheLegacyMapTrimsIdsAndStopsAtTheFirstBadOne(): void
    {
        $map = new RepairGraphItemsLegacyArguments();

        self::assertSame([['--host-id' => '4', '--execute' => true, '--show-sql' => true], null], $map->translate(['--host-id= 4 ', '--execute', '--show-sql=x']));
        self::assertSame([[], LegacyRequest::Help], $map->translate(['--execute', '-H', '--host-id=zz']));
        foreach ([['--host-id=', 'ERROR: You must supply a valid host-id to run this script!'], ['--graph-template-id=1.5', 'ERROR: You must supply a numeric graph-template-id!'], ['--data-template-id', 'ERROR: You must supply a numeric data-template-id!']] as [$argument, $line]) {
            try {
                $map->translate([$argument]);
                self::fail($argument . ' must be refused');
            } catch (InvalidLegacyArgument $error) {
                self::assertSame([$line], $map->invalidOutput($error->argument));
            }
        }
        self::assertSame(['ERROR: Invalid Parameter --bogus', '', ...$map->help()], $map->invalidOutput('--bogus'));
    }
}
