<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Doctrine\DBAL\Connection;
use Kadupul\Graphing\Application\Command\AddTree;
use Kadupul\Graphing\Application\Command\AddTreeNode;
use Kadupul\Graphing\Application\Command\GraphCommandAccess;
use Kadupul\Graphing\Application\Command\ListTreeChoices;
use Kadupul\Graphing\Infrastructure\Symfony\AddTreeCommand;
use Kadupul\Graphing\Infrastructure\Symfony\AddTreeLegacyArguments;
use Kadupul\IdentityAccess\Contract\AuditTrail;
use Kadupul\IdentityAccess\Contract\CollectorTopology;
use Kadupul\IdentityAccess\Infrastructure\Cli\CliConsoleAccess;
use Kadupul\Platform\Infrastructure\Legacy\InstallationVersion;
use Kadupul\Platform\Infrastructure\Symfony\Console\CliPresentation;
use Kadupul\Platform\Infrastructure\Symfony\Console\InvalidLegacyArgument;
use Kadupul\Platform\Infrastructure\Symfony\Console\LegacyRequest;
use Kadupul\Platform\Infrastructure\Symfony\Console\ResultRenderer;
use Kadupul\Tests\Fixtures\ConsoleOperatorDatabase;
use Kadupul\Tests\Fixtures\InMemoryTrees;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;

final class AddTreeCommandTest extends TestCase
{
    use ConsoleOperatorDatabase;

    private string $root;
    private Connection $db;
    private CliPresentation $presentation;

    protected function setUp(): void
    {
        $this->root = $this->installationRoot('kadupul-add-tree-command-');
        $this->db = $this->operatorDatabase(8, 4);
        $this->presentation = new CliPresentation();
        $this->presentation->forLegacy(LegacyRequest::Run);
    }

    private function tester(InMemoryTrees $trees): CommandTester
    {
        $access = new GraphCommandAccess(new CliConsoleAccess($this->db, $this->db), $this->createStub(CollectorTopology::class), $this->createStub(AuditTrail::class));
        $version = new InstallationVersion($this->root, $this->db, new Filesystem(), new MockClock('2031-06-01 00:00:00'));
        $command = new AddTreeCommand(new AddTree($access, $trees, $trees), new AddTreeNode($access, $trees, $trees), new ListTreeChoices($access, $trees), $version, $this->presentation, new ResultRenderer());

        return new CommandTester(new Command(null, $command));
    }

    public function testASuccessPrintsTheOriginalLineAndTheNewIds(): void
    {
        $trees = new InMemoryTrees();
        $tester = $this->tester($trees);

        self::assertSame(0, $tester->execute(['--type' => 'tree', '--name' => ' New ', '--sort-method' => 'numeric']));
        self::assertSame("Tree Created - tree-id: (6)\n", $tester->getDisplay());
        self::assertSame(['New:3'], $trees->trees, 'the name is trimmed');
        self::assertSame(0, $tester->execute(['--type' => 'node', '--node-type' => 'header', '--tree-id' => '5', '--name' => 'Edge']));
        self::assertSame("Added Node node-id: (20)\n", $tester->getDisplay());
    }

    public function testEachNodeRefusalPrintsTheOriginalLineAndExitsOne(): void
    {
        $tester = $this->tester(new InMemoryTrees());
        $header = ['--type' => 'node', '--node-type' => 'header', '--name' => 'Edge'];
        $cases = [
            [$header + ['--tree-id' => '0'], "ERROR: You must supply an existing --tree-id before creating a node.\n"],
            [$header + ['--tree-id' => '6'], "ERROR: Tree 6 does not exist. Try --list-trees\n"],
            [$header + ['--tree-id' => '5', '--parent-node' => 'x'], "ERROR: parent-node x must be a non-negative integer\n"],
            [$header + ['--tree-id' => '5', '--parent-node' => '99'], "ERROR: parent-node 99 does not exist in tree 5.\n"],
            [$header + ['--tree-id' => '5', '--parent-node' => '11'], "ERROR: parent-node 11 is not a header in tree 5.\n"],
            [['--type' => 'node', '--node-type' => 'graph', '--tree-id' => '5', '--graph-id' => '9'], "ERROR: No such graph-id (9) exists. Try --list-graphs\n"],
            [['--type' => 'node', '--node-type' => 'site', '--tree-id' => '5', '--site-id' => '9'], "ERROR: No such site-id (9) exists. Try --list-sites\n"],
            [['--type' => 'node', '--node-type' => 'host', '--tree-id' => '5', '--host-id' => '9'], "ERROR: No such host-id (9) exists. Try --list-hosts\n"],
            [['--type' => 'tree', '--name' => 'Main'], "ERROR: Not adding tree - it already exists - tree-id: (5)\n"],
        ];
        foreach ($cases as [$arguments, $expected]) {
            self::assertSame(1, $tester->execute($arguments), json_encode($arguments, JSON_THROW_ON_ERROR));
            self::assertSame($expected, $tester->getDisplay());
        }
    }

    public function testAnArgumentErrorPrintsTheMessageAndThenTheHelp(): void
    {
        $tester = $this->tester(new InMemoryTrees());

        foreach ([['--type' => 'forest'], ['--type' => 'tree'], ['--type' => 'tree', '--name' => '0'], ['--type' => 'tree', '--name' => 'x', '--sort-method' => 'random'], ['--type' => 'node', '--node-type' => 'leaf'], ['--type' => 'node', '--node-type' => 'header', '--tree-id' => '5']] as $arguments) {
            self::assertSame(1, $tester->execute($arguments), json_encode($arguments, JSON_THROW_ON_ERROR));
            self::assertStringStartsWith('ERROR: ', $tester->getDisplay());
            self::assertStringContainsString("usage: add_tree.php  --type=[tree|node]", $tester->getDisplay());
        }
        $tester->execute(['--type' => 'forest']);
        self::assertStringStartsWith("ERROR: Unknown type: (forest)\nKadupul Add Tree Utility, Version ", $tester->getDisplay());
    }

    public function testAFailedWorkerPrintsTheFailureLine(): void
    {
        $tester = $this->tester(new InMemoryTrees(fails: true));

        self::assertSame(1, $tester->execute(['--type' => 'node', '--node-type' => 'header', '--tree-id' => '5', '--name' => 'Edge'], ['capture_stderr_separately' => true]));
        self::assertSame("ERROR: Failed to create the node.\n", $tester->getErrorOutput());
        self::assertSame('', $tester->getDisplay());
    }

    public function testTheListModesPrintTheirTablesAndQuietDropsTheFrame(): void
    {
        $tester = $this->tester(new InMemoryTrees());

        self::assertSame(0, $tester->execute(['--list-sites' => true]));
        self::assertSame("Known Sites: (id, name)\n2\tBerlin\n\n", $tester->getDisplay());
        self::assertSame(0, $tester->execute(['--list-trees' => true, '--no-headers' => true]));
        self::assertSame("5\tAlphabetic Ordering\tMain\n", $tester->getDisplay());
        self::assertSame(0, $tester->execute(['--list-nodes' => true, '--tree-id' => '5']));
        self::assertSame("Known Tree Nodes: (type, id, parentid, title, attribs)\nHeader\t10\tN/A\tCore\tAlphabetic Ordering\t\nGraph\t11\t10\tTraffic\t\nDevice\t12\t10\tcore1\tGraph Template\t\n\n", $tester->getDisplay());
        self::assertSame(0, $tester->execute(['--list-nodes' => true, '--tree-id' => '5', '--parent-node' => '10']));
        self::assertStringStartsWith("Header\t10\t", $tester->getDisplay(), 'below the root there is no heading');
        self::assertSame(0, $tester->execute(['--list-graphs' => true, '--host-id' => '7']));
        self::assertSame("Known Device Graphs: (id, name, template)\n3\tcore1 - Traffic\tTraffic\t\n\n", $tester->getDisplay());
        self::assertSame(0, $tester->execute(['--list-trees' => true, '--list-hosts' => true]));
        self::assertStringStartsWith('Known Devices:', $tester->getDisplay(), 'the script tried devices before trees');
    }

    public function testListingGraphsNeedsADeviceThatIsNotZero(): void
    {
        $tester = $this->tester(new InMemoryTrees());

        foreach ([[], ['--host-id' => '0'], ['--host-id' => '0.0']] as $extra) {
            self::assertSame(1, $tester->execute(['--list-graphs' => true] + $extra));
            self::assertSame("ERROR: You must supply a host_id before you can list its graphs\nTry --list-hosts\n", $tester->getDisplay());
        }
        self::assertSame(0, $tester->execute(['--list-graphs' => true, '--host-id' => '']), 'an empty id is not zero to PHP 8');
    }

    public function testTheOperatorNeedsTheTreesRealmInEveryMode(): void
    {
        $this->db->executeStatement('DELETE FROM user_auth_realm WHERE realm_id = 4');
        $trees = new InMemoryTrees();
        $tester = $this->tester($trees);

        foreach ([['--type' => 'tree', '--name' => 'New'], ['--type' => 'node', '--node-type' => 'header', '--tree-id' => '5', '--name' => 'a'], ['--list-sites' => true], ['--list-hosts' => true], ['--list-trees' => true], ['--list-nodes' => true], ['--list-graphs' => true, '--host-id' => '7']] as $arguments) {
            self::assertSame(1, $tester->execute($arguments + ['--json' => true]), json_encode($arguments, JSON_THROW_ON_ERROR));
            self::assertSame('denied', json_decode($tester->getDisplay(), true)['status']);
        }
        self::assertSame([[], []], [$trees->trees, $trees->nodes]);
    }

    public function testJsonReportsTheOutcomeAndBinConsoleRejectsBadInputAsInvalid(): void
    {
        $tester = $this->tester(new InMemoryTrees());
        self::assertSame(0, $tester->execute(['--type' => 'tree', '--name' => 'New', '--json' => true]));
        self::assertSame(['created', 6], [json_decode($tester->getDisplay(), true)['status'], json_decode($tester->getDisplay(), true)['tree_id']]);

        $this->presentation = new CliPresentation();
        self::assertSame(Command::INVALID, $this->tester(new InMemoryTrees())->execute(['--type' => 'forest']));
        self::assertSame(Command::FAILURE, $this->tester(new InMemoryTrees())->execute(['--type' => 'tree', '--name' => 'Main']), 'a name that is taken is a refusal, not bad input');
    }

    public function testTheOldArgumentsAreReadAsTheScriptDid(): void
    {
        $arguments = new AddTreeLegacyArguments();

        self::assertSame([['--type' => 'tree', '--name' => '', '--list-trees' => true, '--no-headers' => true], null], $arguments->translate(['--type=tree', '--name', '--list-trees=yes', '--quiet']));
        self::assertSame([['--name' => 'b=c'], null], $arguments->translate(['--name=b=c']));
        self::assertSame(LegacyRequest::Help, $arguments->translate([])[1]);
        self::assertSame(LegacyRequest::Version, $arguments->translate(['--type=tree', '-V', '--bogus'])[1], 'the run ended at the version flag');
        self::assertSame(['ERROR: Invalid Argument: (--bogus)', ''], $arguments->invalid('--bogus=1'));
        $this->expectException(InvalidLegacyArgument::class);
        $arguments->translate(['-d']);
    }
}
