<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Doctrine\DBAL\Connection;
use Kadupul\Graphing\Application\Command\BrokenGraphReport;
use Kadupul\Graphing\Application\Command\GraphCommandAccess;
use Kadupul\Graphing\Application\Command\RemoveBrokenGraphs;
use Kadupul\Graphing\Application\Port\BrokenGraphGroup;
use Kadupul\Graphing\Application\Port\GraphTitle;
use Kadupul\Graphing\Infrastructure\Symfony\RemoveBrokenGraphsCommand;
use Kadupul\Graphing\Infrastructure\Symfony\RemoveBrokenGraphsLegacyArguments;
use Kadupul\IdentityAccess\Contract\AuditTrail;
use Kadupul\IdentityAccess\Contract\CollectorTopology;
use Kadupul\IdentityAccess\Contract\OperatorDatabase;
use Kadupul\IdentityAccess\Infrastructure\Cli\CliConsoleAccess;
use Kadupul\Platform\Infrastructure\Legacy\InstallationVersion;
use Kadupul\Platform\Infrastructure\Symfony\Console\CliPresentation;
use Kadupul\Platform\Infrastructure\Symfony\Console\LegacyRequest;
use Kadupul\Platform\Infrastructure\Symfony\Console\ResultRenderer;
use Kadupul\Tests\Fixtures\ConsoleOperatorDatabase;
use Kadupul\Tests\Fixtures\InMemoryBrokenGraphs;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;

final class RemoveBrokenGraphsCommandTest extends TestCase
{
    use ConsoleOperatorDatabase;

    private const string RULE = '-------------------------------------------------------------------------------------';
    private const string QUERY = "Running Query to find Broken Graphs.  This Query may run for some time depending the number of Graph in your System\n";

    private string $root;
    private Connection $db;
    private CliPresentation $presentation;

    protected function setUp(): void
    {
        $this->root = $this->installationRoot('kadupul-remove-broken-command-');
        $this->db = $this->operatorDatabase(8, 5);
        $this->presentation = new CliPresentation();
    }

    private function graphs(): InMemoryBrokenGraphs
    {
        return new InMemoryBrokenGraphs([new BrokenGraphGroup(1, 'Traffic', [new GraphTitle(10, 'Core traffic'), new GraphTitle(11, 'Edge traffic')])]);
    }

    private function tester(InMemoryBrokenGraphs $graphs): CommandTester
    {
        $access = new GraphCommandAccess(new CliConsoleAccess($this->db, $this->db), $this->createStub(CollectorTopology::class), $this->createStub(AuditTrail::class));
        $version = new InstallationVersion($this->root, $this->db, new Filesystem(), new MockClock('2031-06-01 00:00:00'));
        $command = new RemoveBrokenGraphsCommand(new RemoveBrokenGraphs($access, $graphs, $graphs), $version, $this->presentation, new ResultRenderer());

        return new CommandTester(new Command(null, $command));
    }

    private function versionLine(): string
    {
        return 'Kadupul Remove Broken Graphs Utility, Version 1.3.0 (DB: 1.3.0), Copyright (C) 2004-2031 The Cacti Group';
    }

    private function help(): string
    {
        return $this->versionLine() . "\n\nusage: remove_broken_graphs.php [--report | --remove] [-d|--debug]\n\n"
            . "A utility to remove broken graphs from Kadupul.  A broken Graph is one that.\n"
            . "lacks Data Sources.  This can happen from time to time when working with and modifying templates.\n"
            . "It's important to periodically run this utility especially on larger systems.\n\n"
            . "Optional:\n--report  - Display the Graph Templates and Count of Broken Graphs\n"
            . "--remove  - Remove the Broken Graphs as Reported using the --report Option\n"
            . "--debug   - Display verbose output during execution\n\n";
    }

    public function testLegacyReportIsTheDefaultAndListsTheGraphIds(): void
    {
        $this->presentation->forLegacy(LegacyRequest::Run);
        $graphs = $this->graphs();
        $tester = $this->tester($graphs);

        self::assertSame(0, $tester->execute([]));
        self::assertSame(self::QUERY . "There are 1 Graph Templates with Broken Graphs.\n" . self::RULE . "\nBroken Graph Report\n" . self::RULE . "\n"
            . "Graph Template: Traffic, Contains '2' broken Graphs\nGraphs:\n" . self::RULE . "\n10, 11\n" . self::RULE . "\n", $tester->getDisplay());
        self::assertSame([], $graphs->batches);
    }

    public function testLegacyRemoveNamesEachGraphByTitleAndIdAndRemovesThem(): void
    {
        $this->presentation->forLegacy(LegacyRequest::Run);
        $graphs = $this->graphs();
        $tester = $this->tester($graphs);

        self::assertSame(0, $tester->execute(['--remove' => true]));
        self::assertSame(self::QUERY . "There are 1 Graph Templates with Broken Graphs.\n" . self::RULE . "\nBroken Graph Report Removal\n" . self::RULE . "\n"
            . self::RULE . "\nStarted removing '2' broken Graphs for Graph Template Traffic\n" . self::RULE . "\n"
            . "Removing Graph Core traffic, \"10\"\nRemoving Graph Edge traffic, \"11\"\n" . self::RULE . "\nCompleted removing Graphs for Graph Template Traffic\n", $tester->getDisplay());
        self::assertSame([[10, 11]], $graphs->batches);
    }

    public function testLegacyWithNothingBrokenSaysSo(): void
    {
        $this->presentation->forLegacy(LegacyRequest::Run);
        $tester = $this->tester(new InMemoryBrokenGraphs([]));

        self::assertSame(0, $tester->execute(['--remove' => true]));
        self::assertSame(self::QUERY . "There are no Graph Templates with Broken Graphs.\n", $tester->getDisplay());
    }

    public function testLegacyRefusesBothModesWithTheHelp(): void
    {
        $this->presentation->forLegacy(LegacyRequest::Run);
        $graphs = $this->graphs();
        $tester = $this->tester($graphs);

        self::assertSame(1, $tester->execute(['--report' => true, '--remove' => true]));
        self::assertSame("ERROR: The options --report and --remove are mutually exclusive\n" . $this->help(), $tester->getDisplay());
        self::assertSame(0, $graphs->reads);
    }

    public function testLegacyHelpAndVersionExitZero(): void
    {
        $tester = $this->tester($this->graphs());
        $this->presentation->forLegacy(LegacyRequest::Help);
        self::assertSame(0, $tester->execute([]));
        self::assertSame($this->help(), $tester->getDisplay());

        $this->presentation->forLegacy(LegacyRequest::Version);
        self::assertSame(0, $tester->execute([]));
        self::assertSame($this->versionLine() . "\n", $tester->getDisplay());
    }

    public function testTheGraphIdsWrapToTheTerminalWidth(): void
    {
        $ids = array_map(static fn(int $id): GraphTitle => new GraphTitle(1000 + $id, 't'), range(1, 40));
        $lines = (new RemoveBrokenGraphsLegacyArguments())->run(new BrokenGraphReport(OperatorDatabase::Local, [new BrokenGraphGroup(1, 'T', $ids)], false, 0), 40);

        // The wrapped list is the element after the second rule below 'Graphs:'.
        $wrapped = explode("\n", $lines[8]);
        self::assertGreaterThan(1, count($wrapped));
        foreach ($wrapped as $line) {
            self::assertLessThanOrEqual(35, strlen($line));
        }
    }

    public function testJsonListsTheTemplatesAndAShortRemovalExitsOne(): void
    {
        $tester = $this->tester($this->graphs());
        self::assertSame(0, $tester->execute(['--json' => true]));
        self::assertSame(['status' => 'ok', 'database' => 'local', 'removing' => false, 'broken' => 2, 'removed' => 0, 'templates' => [['graph_template_id' => 1, 'name' => 'Traffic', 'graphs' => [10, 11]]]], json_decode($tester->getDisplay(), true));

        $short = $this->tester(new InMemoryBrokenGraphs([new BrokenGraphGroup(1, 'T', [new GraphTitle(10, 'a'), new GraphTitle(11, 'b')])], 1));
        self::assertSame(1, $short->execute(['--json' => true, '--remove' => true]));
        self::assertSame('partial', json_decode($short->getDisplay(), true)['status']);
    }

    public function testBinConsoleRejectsBothModesAsInvalidInput(): void
    {
        $tester = $this->tester($this->graphs());

        self::assertSame(Command::INVALID, $tester->execute(['--report' => true, '--remove' => true]));
        self::assertStringContainsString('mutually exclusive', $tester->getDisplay());
    }

    public function testAnOperatorWithoutRealm5IsDenied(): void
    {
        $this->db->executeStatement('DELETE FROM user_auth_realm WHERE realm_id = 5');
        $tester = $this->tester($this->graphs());

        self::assertSame(1, $tester->execute(['--json' => true, '--remove' => true]));
        self::assertSame('denied', json_decode($tester->getDisplay(), true)['status']);
    }
}
