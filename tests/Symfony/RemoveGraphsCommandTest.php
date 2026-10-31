<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Doctrine\DBAL\Connection;
use Kadupul\Graphing\Application\Command\GraphCommandAccess;
use Kadupul\Graphing\Application\Command\ListRemovalChoices;
use Kadupul\Graphing\Application\Command\RemoveGraphs;
use Kadupul\Graphing\Application\Port\GraphTitle;
use Kadupul\Graphing\Application\Port\CatalogChoice;
use Kadupul\Graphing\Application\Port\CatalogDevice;
use Kadupul\Graphing\Infrastructure\Symfony\RemoveGraphsCommand;
use Kadupul\Graphing\Infrastructure\Symfony\RemoveGraphsLegacyArguments;
use Kadupul\IdentityAccess\Contract\AuditTrail;
use Kadupul\IdentityAccess\Contract\CollectorTopology;
use Kadupul\IdentityAccess\Infrastructure\Cli\CliConsoleAccess;
use Kadupul\Platform\Infrastructure\Legacy\InstallationVersion;
use Kadupul\Platform\Infrastructure\Symfony\Console\CliPresentation;
use Kadupul\Platform\Infrastructure\Symfony\Console\InvalidLegacyArgument;
use Kadupul\Platform\Infrastructure\Symfony\Console\LegacyRequest;
use Kadupul\Platform\Infrastructure\Symfony\Console\ResultRenderer;
use Kadupul\Tests\Fixtures\ConsoleOperatorDatabase;
use Kadupul\Tests\Fixtures\InMemoryGraphRemoval;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;

final class RemoveGraphsCommandTest extends TestCase
{
    use ConsoleOperatorDatabase;

    private string $root;
    private Connection $db;
    private CliPresentation $presentation;

    protected function setUp(): void
    {
        $this->root = $this->installationRoot('kadupul-remove-graphs-command-');
        $this->db = $this->operatorDatabase(8, 5);
        $this->presentation = new CliPresentation();
        $this->presentation->forLegacy(LegacyRequest::Run);
    }

    private function graphs(): InMemoryGraphRemoval
    {
        return new InMemoryGraphRemoval(
            [new GraphTitle(10, 'Core traffic'), new GraphTitle(11, 'Edge traffic')],
            [new CatalogDevice(3, 'core1', 2, 'Core router')],
            [new CatalogChoice(2, 'Router')],
            [new CatalogChoice(5, 'Traffic'), new CatalogChoice(6, 'Errors')],
        );
    }

    private function tester(InMemoryGraphRemoval $graphs): CommandTester
    {
        $access = new GraphCommandAccess(new CliConsoleAccess($this->db, $this->db), $this->createStub(CollectorTopology::class), $this->createStub(AuditTrail::class));
        $version = new InstallationVersion($this->root, $this->db, new Filesystem(), new MockClock('2031-06-01 00:00:00'));
        $command = new RemoveGraphsCommand(new RemoveGraphs($access, $graphs, $graphs), new ListRemovalChoices($access, $graphs), $version, $this->presentation, new ResultRenderer());

        return new CommandTester(new Command(null, $command));
    }

    public function testACountPrintsOneLineAndPointsAtListAndForce(): void
    {
        $graphs = $this->graphs();
        $tester = $this->tester($graphs);

        self::assertSame(0, $tester->execute(['--host-id' => ['3']]));
        self::assertSame("There are 2 Graphs to Remove.  Use the --force option to remove these Graphs.\n  Use the --list option to view the list of Graphs\n", $tester->getDisplay());
        self::assertSame([], $graphs->batches);
        self::assertSame([3], $graphs->filters[0]->hostIds);
    }

    public function testAListingNeedsNoSelector(): void
    {
        $tester = $this->tester($this->graphs());

        self::assertSame(0, $tester->execute(['--list' => true]));
        self::assertSame("There are 2 Graphs to Remove.  Use the --force option to remove these Graphs.\nID\tGraphName\n10\tCore traffic\n11\tEdge traffic\n", $tester->getDisplay());
    }

    public function testForceRemovesDataSourcesUnlessPreserved(): void
    {
        $graphs = $this->graphs();
        $tester = $this->tester($graphs);
        self::assertSame(0, $tester->execute(['--all' => true, '--force' => true]));
        self::assertSame("There are 2 Graphs to Remove.  Data Sources will be removed if possible.\nDelete Operation Completed\n", $tester->getDisplay());

        self::assertSame(0, $tester->execute(['--all' => true, '--force' => true, '--preserve' => true]));
        self::assertSame("There are 2 Graphs to Remove.  Data Sources will be preserved.\nDelete Operation Completed\n", $tester->getDisplay());
        self::assertSame([[10, 11], [10, 11]], $graphs->batches);
        self::assertSame([false, true], $graphs->kept);
    }

    public function testNoSelectorIsRefusedAndNothingIsRead(): void
    {
        $graphs = $this->graphs();
        $tester = $this->tester($graphs);

        self::assertSame(1, $tester->execute(['--force' => true]));
        self::assertSame("ERROR: The options specified will remove all graphs.  To do this you must use the --all option.  Exiting\n", $tester->getDisplay());
        self::assertSame([], $graphs->filters);
    }

    public function testNothingMatchingSaysSoAndExitsOne(): void
    {
        $tester = $this->tester(new InMemoryGraphRemoval());

        self::assertSame(1, $tester->execute(['--all' => true]));
        self::assertSame("No matching Graphs found.\n", $tester->getDisplay());
    }

    public function testChecksRunInTheOriginalOrder(): void
    {
        $tester = $this->tester($this->graphs());

        // Expressions come first, then --list with --force, then template, graph template and device ids.
        self::assertSame(1, $tester->execute(['--graph-regex' => ['||'], '--list' => true, '--force' => true, '--host-id' => ['x']]));
        self::assertSame("ERROR: Regex specified '||', is not a valid Regex!\n", $tester->getDisplay());

        self::assertSame(1, $tester->execute(['--list' => true, '--force' => true, '--host-id' => ['x']]));
        self::assertSame("The --list and --force options are mutually exclusive.  Pick on or the other.\n", $tester->getDisplay());

        self::assertSame(1, $tester->execute(['--host-id' => ['x'], '--graph-template-id' => ['y'], '--host-template-id' => ['0']]));
        self::assertSame("FATAL: Host Template ID 0 is invalid\n", $tester->getDisplay());

        self::assertSame(1, $tester->execute(['--host-id' => ['x'], '--graph-template-id' => ['-1']]));
        self::assertSame("FATAL: Graph Template ID -1 is invalid\n", $tester->getDisplay());

        self::assertSame(1, $tester->execute(['--host-id' => ['']]));
        self::assertSame("FATAL: Host ID  is invalid\n", $tester->getDisplay());
    }

    public function testTheListModesPrintTheirTables(): void
    {
        $graphs = $this->graphs();
        $tester = $this->tester($graphs);

        self::assertSame(0, $tester->execute(['--list-hosts' => true, '--host-template-id' => ['2']]));
        self::assertSame("Known Devices: (id, hostname, template, description)\n3\tcore1\t2\tCore router\n\n", $tester->getDisplay());
        self::assertSame([[2]], $graphs->templateFilters);

        self::assertSame(0, $tester->execute(['--list-host-templates' => true]));
        self::assertSame("Valid Device Templates: (id, name)\n0\tNone\n2\tRouter\n\n", $tester->getDisplay());

        self::assertSame(0, $tester->execute(['--list-graph-templates' => true]));
        self::assertSame("Known Graph Templates: (id, name)\n5\tTraffic\n6\tErrors\n\n", $tester->getDisplay());
    }

    public function testListHostsWinsOverTheOtherListModes(): void
    {
        $tester = $this->tester($this->graphs());

        self::assertSame(0, $tester->execute(['--list-graph-templates' => true, '--list-hosts' => true, '--list-host-templates' => true]));
        self::assertStringStartsWith('Known Devices:', $tester->getDisplay());
    }

    public function testAnOperatorWithoutGraphAccessIsDeniedInEveryMode(): void
    {
        $this->db->executeStatement('DELETE FROM user_auth_realm WHERE realm_id = 5');
        $graphs = $this->graphs();
        $tester = $this->tester($graphs);

        foreach ([['--all' => true, '--force' => true], ['--list' => true], ['--list-hosts' => true], ['--list-host-templates' => true]] as $arguments) {
            self::assertSame(1, $tester->execute($arguments + ['--json' => true]));
            self::assertSame('denied', json_decode($tester->getDisplay(), true)['status']);
        }
        self::assertSame([], $graphs->batches);
    }

    public function testJsonReportsTheMatchAndTheDataSourceChoice(): void
    {
        $tester = $this->tester($this->graphs());

        self::assertSame(0, $tester->execute(['--all' => true, '--force' => true, '--preserve' => true, '--json' => true]));
        $json = json_decode($tester->getDisplay(), true);
        self::assertSame(['ok', 'remove', 2, 2, 'kept'], [$json['status'], $json['mode'], $json['matched'], $json['removed'], $json['data_sources']]);
    }

    public function testQuietAndGraphTypeAreNamedAsInvalidAndTheRunGoesOn(): void
    {
        $tester = $this->tester($this->graphs());

        self::assertSame(0, $tester->execute(['--host-id' => ['3'], '--ignored' => ['graph-type', 'quiet']]));
        self::assertSame("ERROR: Invalid Argument: (graph-type)\n\nERROR: Invalid Argument: (quiet)\n\nThere are 2 Graphs to Remove.  Use the --force option to remove these Graphs.\n  Use the --list option to view the list of Graphs\n", $tester->getDisplay());
    }

    public function testBinConsoleRejectsInvalidInputAsInvalid(): void
    {
        $this->presentation = new CliPresentation();
        $tester = $this->tester($this->graphs());

        self::assertSame(Command::INVALID, $tester->execute(['--list' => true, '--force' => true]));
    }

    public function testTheOldFlagsBecomeListsAndAnEmptyValueIsKept(): void
    {
        [$input, $request] = (new RemoveGraphsLegacyArguments())->translate(['--host-id=1', '--host-id=2', '--graph-regex', '--all', '--list-hosts']);

        self::assertNull($request);
        self::assertSame(['--all' => true, '--list-hosts' => true, '--host-id' => ['1', '2'], '--graph-regex' => ['']], $input);
        // "--host-id=" with nothing after the "=" was not seen by getopt(), so it is dropped.
        self::assertSame([], (new RemoveGraphsLegacyArguments())->translate(['--host-id=', '--graph-regex='])[0]);
        self::assertSame(LegacyRequest::Help, (new RemoveGraphsLegacyArguments())->translate([])[1]);
        self::assertSame(['--ignored' => ['quiet', 'graph-type']], (new RemoveGraphsLegacyArguments())->translate(['--quiet', '--graph-type=x'])[0]);
    }

    public function testAnUnknownFlagIsRefusedAndHelpIsASpecialRequest(): void
    {
        $arguments = new RemoveGraphsLegacyArguments();
        self::assertSame(LegacyRequest::Help, $arguments->translate(['--all', '--help'])[1]);
        $this->expectException(InvalidLegacyArgument::class);
        $arguments->translate(['--bogus']);
    }
}
