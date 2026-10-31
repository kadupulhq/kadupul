<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Doctrine\DBAL\Connection;
use Kadupul\Graphing\Application\Command\GraphCommandAccess;
use Kadupul\Graphing\Application\Command\ReapplyDataSourceTitles;
use Kadupul\Graphing\Application\Port\DataSourceTitle;
use Kadupul\Graphing\Infrastructure\Symfony\ReapplyDataSourceTitlesCommand;
use Kadupul\Graphing\Infrastructure\Symfony\ReapplyDataSourceTitlesLegacyArguments;
use Kadupul\IdentityAccess\Contract\AuditTrail;
use Kadupul\IdentityAccess\Contract\CollectorTopology;
use Kadupul\IdentityAccess\Infrastructure\Cli\CliConsoleAccess;
use Kadupul\Platform\Infrastructure\Legacy\InstallationVersion;
use Kadupul\Platform\Infrastructure\Symfony\Console\CliPresentation;
use Kadupul\Platform\Infrastructure\Symfony\Console\InvalidLegacyArgument;
use Kadupul\Platform\Infrastructure\Symfony\Console\LegacyRequest;
use Kadupul\Platform\Infrastructure\Symfony\Console\ResultRenderer;
use Kadupul\Tests\Fixtures\ConsoleOperatorDatabase;
use Kadupul\Tests\Fixtures\InMemoryDataSourceTitles;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;

final class ReapplyDataSourceTitlesCommandTest extends TestCase
{
    use ConsoleOperatorDatabase;

    private const string WARNING = "WARNING: Do not interrupt this script.  Interrupting during rename can cause issues\n";

    private string $root;
    private Connection $db;
    private CliPresentation $presentation;

    protected function setUp(): void
    {
        $this->root = $this->installationRoot('kadupul-reapply-ds-titles-command-');
        // User 1, admin, holds Console Access (8) and Devices (3).
        $this->db = $this->operatorDatabase(8, 3);
        $this->presentation = new CliPresentation();
    }

    private function tester(InMemoryDataSourceTitles $sources): CommandTester
    {
        $access = new GraphCommandAccess(new CliConsoleAccess($this->db, $this->db), $this->createStub(CollectorTopology::class), $this->createStub(AuditTrail::class));
        $version = new InstallationVersion($this->root, $this->db, new Filesystem(), new MockClock('2031-06-01 00:00:00'));
        $command = new ReapplyDataSourceTitlesCommand(new ReapplyDataSourceTitles($access, $sources, $sources), $version, $this->presentation, new ResultRenderer());

        return new CommandTester(new Command(null, $command));
    }

    private function versionLine(): string
    {
        return 'Kadupul Reapply Data Source Names Utility, Version 1.3.0 (DB: 1.3.0), Copyright (C) 2004-2031 The Cacti Group';
    }

    private function help(): string
    {
        return $this->versionLine() . "\n\n"
            . "usage: poller_data_sources_reapply_names.php --host-id=[id|all][N1,N2,...] [--filter=string] [--debug]\n\n"
            . "A utility that will recalculate Data Source names for the selected Data Templates.\n\n"
            . "Required:\n"
            . "    --host-id=N|all|N1,N2,... - The devices id, 'all' or a comma delimited list of id's\n\n"
            . "Optional:\n"
            . "    --filter=search           - A Data Template name or Data Source Title to search for\n"
            . "    --debug                   - Display verbose output during execution\n\n";
    }

    /** @return InMemoryDataSourceTitles */
    private static function two(): InMemoryDataSourceTitles
    {
        return new InMemoryDataSourceTitles([new DataSourceTitle(4, 'Traffic'), new DataSourceTitle(9, 'Load')]);
    }

    public function testLegacyPrintsOneDotPerDataSourceWithoutATrailingNewline(): void
    {
        $this->presentation->forLegacy(LegacyRequest::Run);
        $tester = $this->tester(self::two());

        self::assertSame(0, $tester->execute(['--host-id' => 'all']));
        self::assertSame(self::WARNING . '..', $tester->getDisplay());
    }

    public function testLegacyWithNothingToRenameSaysSoWithoutANewline(): void
    {
        $this->presentation->forLegacy(LegacyRequest::Run);
        $tester = $this->tester(new InMemoryDataSourceTitles([]));

        self::assertSame(0, $tester->execute(['--host-id' => '3']));
        self::assertSame('WARNING: No Data Sources where found matching the selected criteria.', $tester->getDisplay());
    }

    public function testLegacyDebugNamesEachDataSourceBeforeAndAfter(): void
    {
        $this->presentation->forLegacy(LegacyRequest::Run);
        $tester = $this->tester(self::two());

        self::assertSame(0, $tester->execute(['--host-id' => 'all', '--debug' => true]));
        self::assertSame(self::WARNING . "DEBUG: There are '2' Data Sources to rename\n"
            . "DEBUG: Data Source Name 'Traffic' starting\nDEBUG: Data Source Rename Done for Data Source 'Renamed 4'\n"
            . "DEBUG: Data Source Name 'Load' starting\nDEBUG: Data Source Rename Done for Data Source 'Renamed 9'\n", $tester->getDisplay());
    }

    public function testTheFilterReachesTheCatalogAsGivenIncludingZero(): void
    {
        $this->presentation->forLegacy(LegacyRequest::Run);
        $sources = self::two();
        $this->tester($sources)->execute(['--host-id' => '1,2', '--filter' => '0']);

        self::assertSame([[1, 2], '0'], [$sources->selections[0]->hostIds, $sources->selections[0]->filter]);
    }

    public function testLegacyRefusesABadSelectorWithoutTouchingAnything(): void
    {
        $this->presentation->forLegacy(LegacyRequest::Run);
        $sources = self::two();
        $tester = $this->tester($sources);

        self::assertSame(1, $tester->execute(['--host-id' => '0,1']));
        self::assertSame([], $sources->selections);
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

    public function testJsonListsTheDataSourcesAndAShortRunExitsOne(): void
    {
        $tester = $this->tester(self::two());
        self::assertSame(0, $tester->execute(['--host-id' => 'all', '--json' => true]));
        self::assertSame(['status' => 'ok', 'database' => 'local', 'dry_run' => false, 'selected' => 2, 'reapplied' => 2, 'data_sources' => [4, 9]], json_decode($tester->getDisplay(), true));

        $short = $this->tester(new InMemoryDataSourceTitles([new DataSourceTitle(4, 'a'), new DataSourceTitle(9, 'b')], 1));
        self::assertSame(1, $short->execute(['--host-id' => 'all', '--json' => true]));
        self::assertSame('partial', json_decode($short->getDisplay(), true)['status']);
    }

    public function testADryRunRenamesNothing(): void
    {
        $sources = self::two();
        $tester = $this->tester($sources);

        self::assertSame(0, $tester->execute(['--host-id' => 'all', '--dry-run' => true, '--json' => true]));
        self::assertSame([], $sources->batches);
        self::assertTrue(json_decode($tester->getDisplay(), true)['dry_run']);
    }

    public function testAnInvalidSelectorIsInvalidInputUnderBinConsole(): void
    {
        $tester = $this->tester(self::two());

        self::assertSame(Command::INVALID, $tester->execute(['--host-id' => 'x']));
        self::assertStringContainsString('positive device IDs', $tester->getDisplay());
    }

    public function testAnOperatorWithoutRealm3IsDenied(): void
    {
        $this->db->executeStatement('DELETE FROM user_auth_realm WHERE realm_id = 3');
        $tester = $this->tester(self::two());

        self::assertSame(1, $tester->execute(['--host-id' => 'all', '--json' => true]));
        self::assertSame('denied', json_decode($tester->getDisplay(), true)['status']);
    }

    public function testAQuoteInARenamedTitleIsEscapedAsTheOriginalDid(): void
    {
        [$lines] = (new ReapplyDataSourceTitlesLegacyArguments())->run([new DataSourceTitle(4, 'a')], ["It's"], true);

        self::assertSame("DEBUG: Data Source Rename Done for Data Source 'It\\'s'", $lines[3]);
    }

    public function testTheLegacyMapKeepsEveryFlagAndEmptyValueTheOriginalAccepted(): void
    {
        $map = new ReapplyDataSourceTitlesLegacyArguments();

        self::assertSame([['--host-id' => '4', '--filter' => 'x=y', '--debug' => true], null], $map->translate(['--id=4', '-s=x=y', '-d']));
        self::assertSame([['--host-id' => '', '--filter' => ''], null], $map->translate(['--host-id', '--filter=']));
        self::assertSame([[], LegacyRequest::Help], $map->translate(['--debug', '-h']));
        self::assertSame([[], LegacyRequest::Version], $map->translate(['-V']));
        $this->expectException(InvalidLegacyArgument::class);
        $map->translate(['--host-id=1', '=2']);
    }
}
