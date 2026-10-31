<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Doctrine\DBAL\Connection;
use Kadupul\Graphing\Application\Command\AddGraphs;
use Kadupul\Graphing\Application\Command\GraphCommandAccess;
use Kadupul\Graphing\Infrastructure\Symfony\AddGraphsCommand;
use Kadupul\Graphing\Infrastructure\Symfony\AddGraphsLegacyArguments;
use Kadupul\IdentityAccess\Contract\AuditTrail;
use Kadupul\IdentityAccess\Contract\CollectorTopology;
use Kadupul\IdentityAccess\Infrastructure\Cli\CliConsoleAccess;
use Kadupul\Platform\Infrastructure\Legacy\InstallationVersion;
use Kadupul\Platform\Infrastructure\Symfony\Console\CliPresentation;
use Kadupul\Platform\Infrastructure\Symfony\Console\InvalidLegacyArgument;
use Kadupul\Platform\Infrastructure\Symfony\Console\LegacyRequest;
use Kadupul\Platform\Infrastructure\Symfony\Console\ResultRenderer;
use Kadupul\Tests\Fixtures\ConsoleOperatorDatabase;
use Kadupul\Tests\Fixtures\InMemoryGraphs;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;

final class AddGraphsCommandTest extends TestCase
{
    use ConsoleOperatorDatabase;

    private string $root;
    private Connection $db;
    private CliPresentation $presentation;

    protected function setUp(): void
    {
        $this->root = $this->installationRoot('kadupul-add-graphs-command-');
        $this->db = $this->operatorDatabase(8, 5);
        $this->presentation = new CliPresentation();
        $this->presentation->forLegacy(LegacyRequest::Run);
    }

    private function tester(InMemoryGraphs $graphs): CommandTester
    {
        $access = new GraphCommandAccess(new CliConsoleAccess($this->db, $this->db), $this->createStub(CollectorTopology::class), $this->createStub(AuditTrail::class));
        $version = new InstallationVersion($this->root, $this->db, new Filesystem(), new MockClock('2031-06-01 00:00:00'));
        $command = new AddGraphsCommand(new AddGraphs($access, $graphs, $graphs), $version, $this->presentation, new ResultRenderer());

        return new CommandTester(new Command(null, $command));
    }

    public function testASuccessPrintsTheOriginalLineForEachGraph(): void
    {
        $graphs = new InMemoryGraphs(indexes: ['1', '2'], existing: ['3:7:2' => 41]);
        $tester = $this->tester($graphs);

        self::assertSame(0, $tester->execute(['--host-id' => '7', '--graph-type' => 'cg', '--graph-template-id' => '3', '--force' => true]));
        self::assertSame("Graph Added - Graph[101] - DS[201]\n", $tester->getDisplay());
        self::assertSame(0, $tester->execute(['--host-id' => '7', '--graph-type' => 'ds', '--graph-template-id' => '3', '--snmp-query-id' => '1', '--snmp-query-type-id' => '2', '--snmp-field' => ['ifName'], '--snmp-value' => ['eth0']]));
        self::assertSame("Graph Added - Graph[102] - DS[502, 202]\nNOTE: Not Adding Graph - this graph already exists - graph-id: (41) - data-source-id: (1041)\n", $tester->getDisplay());
    }

    public function testAnExistingTemplateGraphIsANoteAndExitOne(): void
    {
        $tester = $this->tester(new InMemoryGraphs(existing: ['3:7' => 41]));

        self::assertSame(1, $tester->execute(['--host-id' => '7', '--graph-type' => 'cg', '--graph-template-id' => '3']));
        self::assertSame("NOTE: Not Adding Graph - this graph already exists - graph-id: (41) - data-source-id: (1041)\n", $tester->getDisplay());
    }

    public function testAGraphTheWhitelistRefusesPrintsItsLineAndExitsZero(): void
    {
        $tester = $this->tester(new InMemoryGraphs(refuseFrom: 1));

        self::assertSame(0, $tester->execute(['--host-id' => '7', '--graph-type' => 'cg', '--graph-template-id' => '3']));
        self::assertSame("Graph Not Added due to whitelist check failure.\n", $tester->getDisplay());
    }

    public function testEachRefusalPrintsTheOriginalLines(): void
    {
        $tester = $this->tester(new InMemoryGraphs());
        $cg = ['--graph-type' => 'cg'];
        $cases = [
            [['--host-id' => '9'] + $cg, "ERROR: Unknown Host ID (9)\nTry --list-hosts\n"],
            [$cg, "ERROR: Unknown Host ID (0)\nTry --list-hosts\n"],
            [['--host-id' => '7'] + $cg, "ERROR: Unknown graph-template-id (0)\nTry --list-graph-templates\n"],
            [['--host-id' => '7', '--graph-template-id' => '3', '--graph-type' => 'xx'], "ERROR: Graph Types must be either 'cg' or 'ds'\n"],
            [['--snmp-query-id' => '9'], "ERROR: Unknown snmp-query-id (9)\nTry --list-snmp-queries\n"],
            [['--snmp-query-id' => '1', '--snmp-query-type-id' => '9'], "ERROR: Unknown snmp-query-type-id (9)\nTry --snmp-query-id=1 --list-query-types\n"],
            [['--host-id' => '7', '--graph-type' => 'dq', '--snmp-field' => ['ifName'], '--snmp-value' => ['eth0'], '--snmp-value-regex' => ['^e']], "ERROR: You can't supply --snmp-value and --snmp-value-regex at the same time\n"],
            [['--host-id' => '7', '--graph-type' => 'dq', '--snmp-field' => ['ifName', 'ifStatus'], '--snmp-value' => ['eth0']], "ERROR: number of --snmp-field and --snmp-value does not match\n"],
            [['--host-id' => '7', '--graph-type' => 'dq', '--snmp-field' => ['ifName'], '--snmp-value-regex' => ['^e', '^l']], "ERROR: number of --snmp-field (1) and --snmp-value-regex (2) does not match\n"],
            [['--host-id' => '7', '--graph-type' => 'dq', '--snmp-field' => ['ifName']], "ERROR: You must supply a --snmp-value or --snmp-value-regex option with --snmp-field\n"],
            [['--host-id' => '7', '--graph-type' => 'dq', '--snmp-field' => ['x'], '--snmp-value' => ['y']], "ERROR: Unknown snmp-field Array for host 7\nTry --list-snmp-fields\n"],
            [['--host-id' => '7', '--graph-type' => 'dq', '--snmp-field' => ['ifName'], '--snmp-value' => ['y']], "ERROR: Unknown snmp-value for field ifName - y\nTry --snmp-field=ifName --list-snmp-values\n"],
            [['--host-id' => '7', '--graph-type' => 'dq', '--snmp-field' => ['ifName'], '--snmp-value-regex' => ['^z']], "ERROR: Unknown snmp-value for field ifName - \nTry --snmp-field=ifName --list-snmp-values\n"],
            [['--host-id' => '7', '--list-snmp-values' => true], "ERROR: You must supply an snmp-field before you can list its values\nTry --list-snmp-fields\n"],
            [['--host-id' => '7', '--graph-template-id' => '3', '--graph-type' => 'cg', '--input-fields' => 'zz=1'], "ERROR: Unknown input-field (zz)\nTry --list-input-fields\n"],
            [['--list-input-fields' => true], "ERROR: You must supply an graph-template-id before you can list its input fields\nTry --graph-template-id=[ID] --list-input-fields\n"],
            [['--list-graph-templates' => true, '--host-template-id' => '9'], "ERROR: You must supply a valid --host-template-id before you can list its graph templates\nTry --list-graph-template-id --host-template-id=[ID]\n"],
        ];
        foreach ($cases as [$arguments, $expected]) {
            self::assertSame(1, $tester->execute($arguments), json_encode($arguments, JSON_THROW_ON_ERROR));
            self::assertSame($expected, $tester->getDisplay(), json_encode($arguments, JSON_THROW_ON_ERROR));
        }
    }

    public function testAMissingDataQueryOptionPrintsTheMessageAndThenTheHelp(): void
    {
        $tester = $this->tester(new InMemoryGraphs());

        self::assertSame(1, $tester->execute(['--host-id' => '7', '--graph-template-id' => '3', '--graph-type' => 'ds']));
        self::assertStringStartsWith("ERROR: For graph-type of 'ds' you must supply more options\nKadupul Add Graphs Utility, Version ", $tester->getDisplay());
        self::assertStringContainsString("\nusage: add_graphs.php --graph-type=[cg|ds] --graph-template-id=[ID]\n", $tester->getDisplay());
    }

    public function testNoIndexNamesTheFieldsTheValuesAndTheDevice(): void
    {
        $tester = $this->tester(new InMemoryGraphs(indexes: []));

        self::assertSame(1, $tester->execute(['--host-id' => '7', '--graph-template-id' => '3', '--graph-type' => 'ds', '--snmp-query-id' => '1', '--snmp-query-type-id' => '2', '--snmp-field' => ['ifName', 'ifStatus'], '--snmp-value' => ['a', 'b']]));
        self::assertSame("ERROR: Could not find one of more snmp-fields ifName,ifStatus with values (a,b) for host-id 7 (core1)\nTry --host-id=7 --list-snmp-fields\n", $tester->getDisplay());
    }

    public function testARepeatedOptionAndABadReindexMethodAreRefusedBeforeAnythingIsRead(): void
    {
        $graphs = new InMemoryGraphs();
        $tester = $this->tester($graphs);

        self::assertSame(1, $tester->execute(['--host-id' => '7', '--repeated' => ['host-id']]));
        self::assertSame("ERROR: Multiple values specified for non-multi argument: (host-id)\n\n", $tester->getDisplay());
        self::assertSame(1, $tester->execute(['--reindex-method' => 'sideways']));
        self::assertSame("ERROR: You must supply a valid reindex method for this graph!\n", $tester->getDisplay());
        foreach ([['none', 0], ['UPTIME', 1], ['Index', 2], ['fields', 3], ['0', 0], ['2', 2], ['1.5', 2]] as [$method, $expected]) {
            $tester->execute(['--host-id' => '8', '--graph-template-id' => '3', '--graph-type' => 'ds', '--snmp-query-id' => '1', '--snmp-query-type-id' => '2', '--snmp-field' => ['ifName'], '--snmp-value' => ['a'], '--reindex-method' => $method]);
            self::assertSame($expected, (int) explode(':', (string) end($graphs->associations))[2], "method {$method}");
        }
    }

    public function testTheListModesPrintTheirTablesAndQuietDropsTheFrame(): void
    {
        $tester = $this->tester(new InMemoryGraphs());

        self::assertSame(0, $tester->execute(['--list-hosts' => true]));
        self::assertSame("Known Devices: (id, hostname, template, description)\n7\tcore1\t2\tCore router\n8\tsw1\t0\tSwitch\n\n", $tester->getDisplay());
        self::assertSame(0, $tester->execute(['--list-graph-templates' => true, '--no-headers' => true]));
        self::assertSame("3\tTraffic\n4\tLoad\n", $tester->getDisplay());
        self::assertSame(0, $tester->execute(['--list-input-fields' => true, '--graph-template-id' => '4']));
        self::assertSame("Known Input Fields: (name, default, description)\n9:index_type\t\tIndex Type\n9:index_value\tx\tIndex Value\n\n", $tester->getDisplay());
        self::assertSame(0, $tester->execute(['--list-snmp-queries' => true]));
        self::assertSame("Known SNMP Queries: (id, name)\n1\tInterfaces\n\n", $tester->getDisplay());
        self::assertSame(0, $tester->execute(['--list-query-types' => true, '--snmp-query-id' => '1']));
        self::assertSame("Known SNMP Query Types: (id, name)\n2\tTraffic\n\n", $tester->getDisplay());
        self::assertSame(0, $tester->execute(['--list-snmp-fields' => true, '--host-id' => '7']));
        self::assertSame("Known SNMP Fields for host-id 7: (name)\nifName\nifStatus\n\n", $tester->getDisplay());
        self::assertSame(0, $tester->execute(['--list-snmp-values' => true, '--host-id' => '7', '--snmp-field' => ['ifStatus', 'ifStatus']]));
        self::assertSame(str_repeat("Known SNMP Values for Field  and host-id 7: (name)\nUp\nDown\n\n", 2), $tester->getDisplay());
    }

    public function testTheOperatorNeedsTheGraphsRealmInEveryMode(): void
    {
        $this->db->executeStatement('DELETE FROM user_auth_realm WHERE realm_id = 5');
        $graphs = new InMemoryGraphs();
        $tester = $this->tester($graphs);

        foreach ([['--host-id' => '7', '--graph-type' => 'cg', '--graph-template-id' => '3'], ['--list-hosts' => true], ['--list-graph-templates' => true], ['--list-snmp-queries' => true]] as $arguments) {
            self::assertSame(1, $tester->execute($arguments + ['--json' => true]), json_encode($arguments, JSON_THROW_ON_ERROR));
            self::assertSame('denied', json_decode($tester->getDisplay(), true)['status']);
        }
        self::assertSame([], $graphs->drafts);
    }

    public function testAFailedWriteKeepsWhatWasPrintedAndWritesTheFailureToStandardError(): void
    {
        $tester = $this->tester(new InMemoryGraphs(indexes: ['1', '2'], failFrom: 2));

        self::assertSame(1, $tester->execute(['--host-id' => '7', '--graph-template-id' => '3', '--graph-type' => 'ds', '--snmp-query-id' => '1', '--snmp-query-type-id' => '2', '--snmp-field' => ['ifName'], '--snmp-value' => ['a']], ['capture_stderr_separately' => true]));
        self::assertSame("Graph Added - Graph[101] - DS[501, 201]\n", $tester->getDisplay());
        self::assertSame("ERROR: Failed to create the graph.\n", $tester->getErrorOutput());
    }

    public function testJsonReportsTheOutcomeAndBinConsoleRejectsBadInputAsInvalid(): void
    {
        $tester = $this->tester(new InMemoryGraphs());
        self::assertSame(0, $tester->execute(['--host-id' => '7', '--graph-type' => 'cg', '--graph-template-id' => '3', '--json' => true]));
        $report = json_decode($tester->getDisplay(), true);
        self::assertSame(['ok', 'created', 101], [$report['status'], $report['graphs'][0]['result'], $report['graphs'][0]['graph_id']]);
        self::assertSame(0, $tester->execute(['--list-snmp-queries' => true, '--json' => true]));
        self::assertSame([['id' => 1, 'name' => 'Interfaces']], json_decode($tester->getDisplay(), true)['snmp_queries']);

        $this->presentation = new CliPresentation();
        self::assertSame(Command::INVALID, $this->tester(new InMemoryGraphs())->execute(['--host-id' => '7', '--graph-template-id' => '3', '--graph-type' => 'xx']));
        self::assertSame(Command::FAILURE, $this->tester(new InMemoryGraphs())->execute(['--host-id' => '9', '--graph-type' => 'cg']), 'an unknown device is a refusal, not bad input');
    }

    public function testTheOldArgumentsAreReadAsTheScriptDid(): void
    {
        $arguments = new AddGraphsLegacyArguments();

        self::assertSame([['--host-id' => '', '--force' => true, '--no-headers' => true, '--snmp-field' => ['a', 'b']], null], $arguments->translate(['--host-id', '--force', '--quiet', '--snmp-field=a', '--snmp-field=b']));
        self::assertSame([['--graph-title' => 'b=c'], null], $arguments->translate(['--graph-title=b=c']));
        self::assertSame([['--host-id' => '1', '--force' => true, '--repeated' => ['host-id', 'force']], null], $arguments->translate(['--host-id=1', '--force', '--host-id=2', '--force']), 'repeated options, in the order they first appeared');
        self::assertSame([[], null], $arguments->translate(['--host-id=']), 'an option written with nothing after the equals sign was not seen');
        self::assertSame(LegacyRequest::Usage, $arguments->translate([])[1]);
        self::assertSame(LegacyRequest::Version, $arguments->translate(['--host-id=1', '-V', '--bogus'])[1], 'the run ended at the version flag');
        self::assertSame(['ERROR: Invalid Argument: (bogus)', ''], $arguments->invalid('--bogus=1'));
        $this->expectException(InvalidLegacyArgument::class);
        $arguments->translate(['--bogus']);
    }
}
