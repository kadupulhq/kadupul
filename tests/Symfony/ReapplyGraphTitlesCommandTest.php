<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Doctrine\DBAL\Connection;
use Kadupul\Graphing\Application\Command\GraphCommandAccess;
use Kadupul\Graphing\Application\Command\ReapplyGraphTitles;
use Kadupul\Graphing\Application\Port\GraphTitle;
use Kadupul\Graphing\Infrastructure\Symfony\ReapplyGraphTitlesCommand;
use Kadupul\Graphing\Infrastructure\Symfony\ReapplyGraphTitlesLegacyArguments;
use Kadupul\IdentityAccess\Contract\AuditTrail;
use Kadupul\IdentityAccess\Contract\CollectorTopology;
use Kadupul\IdentityAccess\Infrastructure\Cli\CliConsoleAccess;
use Kadupul\Platform\Infrastructure\Legacy\InstallationVersion;
use Kadupul\Platform\Infrastructure\Symfony\Console\CliPresentation;
use Kadupul\Platform\Infrastructure\Symfony\Console\InvalidLegacyArgument;
use Kadupul\Platform\Infrastructure\Symfony\Console\LegacyRequest;
use Kadupul\Platform\Infrastructure\Symfony\Console\ResultRenderer;
use Kadupul\Tests\Fixtures\ConsoleOperatorDatabase;
use Kadupul\Tests\Fixtures\InMemoryGraphTitles;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;

final class ReapplyGraphTitlesCommandTest extends TestCase
{
    use ConsoleOperatorDatabase;

    private const string WARNING = "WARNING: Do not interrupt this script.  Interrupting during rename can cause issues\n";

    private string $root;
    private Connection $db;
    private CliPresentation $presentation;

    protected function setUp(): void
    {
        $this->root = $this->installationRoot('kadupul-reapply-titles-command-');
        // User 1, admin, holds Console Access (8) and Graphs (5).
        $this->db = $this->operatorDatabase(8, 5);
        $this->presentation = new CliPresentation();
    }

    private function tester(InMemoryGraphTitles $graphs): CommandTester
    {
        $access = new GraphCommandAccess(new CliConsoleAccess($this->db, $this->db), $this->createStub(CollectorTopology::class), $this->createStub(AuditTrail::class));
        $version = new InstallationVersion($this->root, $this->db, new Filesystem(), new MockClock('2031-06-01 00:00:00'));
        $command = new ReapplyGraphTitlesCommand(new ReapplyGraphTitles($access, $graphs, $graphs), $version, $this->presentation, new ResultRenderer());

        return new CommandTester(new Command(null, $command));
    }

    private function versionLine(): string
    {
        return 'Kadupul Reapply graph Names Utility, Version 1.3.0 (DB: 1.3.0), Copyright (C) 2004-2031 The Cacti Group';
    }

    private function help(): string
    {
        return $this->versionLine() . "\n\n"
            . "usage: poller_graphs_reapply_names.php --host-id=[id|all][N1,N2,...] [--filter=[string] [--debug]\n\n"
            . "A utility to reapply Kadupul Graph naming rules to existing Graphs in bulk.\n\n"
            . "Required:\n"
            . "    --host-id=id|all|N1,N2,... - The devices id, 'all' or a comma delimited list of id's\n\n"
            . "Optional:\n"
            . "    --filter=string            - A Graph Template name or Graph Title to search for\n"
            . "    --debug                    - Display verbose output during execution\n\n";
    }

    /** @return InMemoryGraphTitles */
    private static function two(): InMemoryGraphTitles
    {
        return new InMemoryGraphTitles([new GraphTitle(4, 'Traffic'), new GraphTitle(9, 'Load')]);
    }

    public function testLegacyPrintsOneDotPerGraphWithoutATrailingNewline(): void
    {
        $this->presentation->forLegacy(LegacyRequest::Run);
        $tester = $this->tester(self::two());

        self::assertSame(0, $tester->execute(['--host-id' => 'all']));
        self::assertSame(self::WARNING . '..', $tester->getDisplay());
    }

    public function testLegacyWithNothingToRenameEndsAfterTheWarning(): void
    {
        $this->presentation->forLegacy(LegacyRequest::Run);
        $tester = $this->tester(new InMemoryGraphTitles([]));

        self::assertSame(0, $tester->execute(['--host-id' => '3']));
        self::assertSame(self::WARNING, $tester->getDisplay());
    }

    public function testLegacyDebugNamesEachGraphBeforeAndAfter(): void
    {
        $this->presentation->forLegacy(LegacyRequest::Run);
        $tester = $this->tester(self::two());

        self::assertSame(0, $tester->execute(['--host-id' => 'all', '--debug' => true]));
        self::assertSame(self::WARNING . "DEBUG: There are '2' Graphs to rename\n"
            . "DEBUG: Graph Name 'Traffic' starting\nDEBUG: Graph Rename Done for Graph 'Traffic'\n"
            . "DEBUG: Graph Name 'Load' starting\nDEBUG: Graph Rename Done for Graph 'Load'\n", $tester->getDisplay());
    }

    public function testTheFilterReachesTheCatalogAsGivenIncludingZero(): void
    {
        $this->presentation->forLegacy(LegacyRequest::Run);
        $graphs = self::two();
        $this->tester($graphs)->execute(['--host-id' => '1,2', '--filter' => '0']);

        self::assertSame([[1, 2], '0'], [$graphs->selections[0]->hostIds, $graphs->selections[0]->filter]);
    }

    public function testLegacyRefusesABadSelectorWithoutTouchingAnything(): void
    {
        $this->presentation->forLegacy(LegacyRequest::Run);
        $graphs = self::two();
        $tester = $this->tester($graphs);

        self::assertSame(1, $tester->execute(['--host-id' => '0,1']));
        self::assertSame([], $graphs->selections);
        self::assertSame(1, $tester->execute(['--debug' => true]));
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

    public function testJsonListsTheGraphsAndAShortRunExitsOne(): void
    {
        $tester = $this->tester(self::two());
        self::assertSame(0, $tester->execute(['--host-id' => 'all', '--json' => true]));
        self::assertSame(['status' => 'ok', 'database' => 'local', 'dry_run' => false, 'selected' => 2, 'reapplied' => 2, 'graphs' => [4, 9]], json_decode($tester->getDisplay(), true));

        $short = $this->tester(new InMemoryGraphTitles([new GraphTitle(4, 'a'), new GraphTitle(9, 'b')], 1));
        self::assertSame(1, $short->execute(['--host-id' => 'all', '--json' => true]));
        self::assertSame('partial', json_decode($short->getDisplay(), true)['status']);
    }

    public function testADryRunRenamesNothing(): void
    {
        $graphs = self::two();
        $tester = $this->tester($graphs);

        self::assertSame(0, $tester->execute(['--host-id' => 'all', '--dry-run' => true, '--json' => true]));
        self::assertSame([], $graphs->batches);
        self::assertTrue(json_decode($tester->getDisplay(), true)['dry_run']);
    }

    public function testAnInvalidSelectorIsInvalidInputUnderBinConsole(): void
    {
        $tester = $this->tester(self::two());

        self::assertSame(Command::INVALID, $tester->execute(['--host-id' => 'x']));
        self::assertStringContainsString('positive device IDs', $tester->getDisplay());
    }

    public function testAnOperatorWithoutRealm5IsDenied(): void
    {
        $this->db->executeStatement('DELETE FROM user_auth_realm WHERE realm_id = 5');
        $tester = $this->tester(self::two());

        self::assertSame(1, $tester->execute(['--host-id' => 'all', '--json' => true]));
        self::assertSame('denied', json_decode($tester->getDisplay(), true)['status']);
    }

    public function testTheLegacyMapKeepsEveryFlagAndEmptyValueTheOriginalAccepted(): void
    {
        $map = new ReapplyGraphTitlesLegacyArguments();

        self::assertSame([['--host-id' => '4', '--filter' => 'x=y', '--debug' => true], null], $map->translate(['-id=4', '-s=x=y', '-d']));
        self::assertSame([['--host-id' => '', '--filter' => ''], null], $map->translate(['--host-id', '--filter=']));
        self::assertSame([[], LegacyRequest::Help], $map->translate(['--debug', '-h']));
        self::assertSame([[], LegacyRequest::Version], $map->translate(['-V']));
        $this->expectException(InvalidLegacyArgument::class);
        $map->translate(['--host-id=1', '=2']);
    }
}
