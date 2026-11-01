<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Doctrine\DBAL\Connection;
use Kadupul\Graphing\Application\Command\AssociateDataQuery;
use Kadupul\Graphing\Application\Command\GraphCommandAccess;
use Kadupul\Graphing\Application\Command\ListDataQueryChoices;
use Kadupul\Graphing\Infrastructure\Symfony\AssociateDataQueryCommand;
use Kadupul\Graphing\Infrastructure\Symfony\AssociateDataQueryLegacyArguments;
use Kadupul\IdentityAccess\Contract\AuditTrail;
use Kadupul\IdentityAccess\Contract\CollectorTopology;
use Kadupul\IdentityAccess\Infrastructure\Cli\CliConsoleAccess;
use Kadupul\Platform\Infrastructure\Legacy\InstallationVersion;
use Kadupul\Platform\Infrastructure\Symfony\Console\CliPresentation;
use Kadupul\Platform\Infrastructure\Symfony\Console\InvalidLegacyArgument;
use Kadupul\Platform\Infrastructure\Symfony\Console\LegacyRequest;
use Kadupul\Platform\Infrastructure\Symfony\Console\ResultRenderer;
use Kadupul\Tests\Fixtures\ConsoleOperatorDatabase;
use Kadupul\Tests\Fixtures\InMemoryDataQueryAssociation;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;

final class AssociateDataQueryCommandTest extends TestCase
{
    use ConsoleOperatorDatabase;

    private string $root;
    private Connection $db;
    private CliPresentation $presentation;

    protected function setUp(): void
    {
        $this->root = $this->installationRoot('kadupul-add-data-query-command-');
        $this->db = $this->operatorDatabase(8, 3);
        $this->presentation = new CliPresentation();
        $this->presentation->forLegacy(LegacyRequest::Run);
    }

    private function tester(InMemoryDataQueryAssociation $association): CommandTester
    {
        $access = new GraphCommandAccess(new CliConsoleAccess($this->db, $this->db), $this->createStub(CollectorTopology::class), $this->createStub(AuditTrail::class));
        $version = new InstallationVersion($this->root, $this->db, new Filesystem(), new MockClock('2031-06-01 00:00:00'));
        $command = new AssociateDataQueryCommand(new AssociateDataQuery($access, $association, $association), new ListDataQueryChoices($access, $association), $version, $this->presentation, new ResultRenderer());

        return new CommandTester(new Command(null, $command));
    }

    public function testSuccessEchoesTheIdsAndTheMethodAsTyped(): void
    {
        $association = new InMemoryDataQueryAssociation();
        $tester = $this->tester($association);

        self::assertSame(0, $tester->execute(['--host-id' => ' 7e0 ', '--data-query-id' => '3', '--reindex-method' => 'Uptime']));
        self::assertSame("Success - Host (7e0: core1) data query (3: Interfaces) reindex method (1: Uptime)\n", $tester->getDisplay());
        self::assertSame(0, $tester->execute(['--host-id' => '7', '--data-query-id' => '3', '--reindex-method' => '1.5']));
        self::assertSame("Success - Host (7: core1) data query (3: Interfaces) reindex method (1.5: )\n", $tester->getDisplay());
        self::assertSame([[7, 3, 1], [7, 3, '1.5']], $association->associated);
    }

    public function testEachRefusalPrintsTheOriginalLineAndExitsOne(): void
    {
        $association = new InMemoryDataQueryAssociation();
        $tester = $this->tester($association);
        $ok = ['--host-id' => '7', '--data-query-id' => '3', '--reindex-method' => '2'];
        $cases = [
            [['--host-id' => '8'] + $ok, "ERROR: Unknown Host Id (8)\n"],
            [['--data-query-id' => '99'] + $ok, "ERROR: Unknown Data Query Id (99)\n"],
            [['--data-query-id' => '4', '--reindex-method' => '1'] + $ok, "ERROR: Data Query is already associated for host: (7: core1) data query (4: Disks) reindex method (1: Uptime)\n"],
            [['--host-id' => 'x'] + $ok, "ERROR: You must supply a valid host-id to run this script!\n"],
            [['--data-query-id' => ' x'] + $ok, "ERROR: You must supply a numeric data-query-id for all hosts!\n"],
            [['--reindex-method' => '4'] + $ok, "ERROR: You must supply a valid reindex method for all hosts!\n"],
            [['--reindex-method' => ''] + $ok, "ERROR: You must supply a valid reindex method for all hosts!\n"],
            [array_diff_key($ok, ['--host-id' => 1]), "ERROR: You must supply a valid host-id for all hosts!\n"],
            [array_diff_key($ok, ['--data-query-id' => 1]), "ERROR: You must supply a valid data-query-id for all hosts!\n"],
            [array_diff_key($ok, ['--reindex-method' => 1]), "ERROR: You must supply a valid reindex-method for all hosts!\n"],
        ];
        foreach ($cases as [$arguments, $expected]) {
            self::assertSame(1, $tester->execute($arguments), json_encode($arguments, JSON_THROW_ON_ERROR));
            self::assertSame($expected, $tester->getDisplay());
        }
        self::assertSame([], $association->associated);
    }

    public function testAFailedWorkerPrintsTheFailureLine(): void
    {
        $tester = $this->tester(new InMemoryDataQueryAssociation(fails: true));

        self::assertSame(1, $tester->execute(['--host-id' => '7', '--data-query-id' => '3', '--reindex-method' => 'none']));
        self::assertSame("ERROR: Failed to add this data query for host (7: core1) data query (3: Interfaces) reindex method (0: None)\n", $tester->getDisplay());
    }

    public function testTheListModesPrintTheirTablesAndQuietDropsTheFrame(): void
    {
        $tester = $this->tester(new InMemoryDataQueryAssociation());

        self::assertSame(0, $tester->execute(['--list-hosts' => true]));
        self::assertSame("Known Devices: (id, hostname, template, description)\n7\tcore1\t2\tCore router\n\n", $tester->getDisplay());
        self::assertSame(0, $tester->execute(['--list-data-queries' => true]));
        self::assertSame("Known SNMP Queries: (id, name)\n3\tInterfaces\n4\tDisks\n\n", $tester->getDisplay());
        self::assertSame(0, $tester->execute(['--list-data-queries' => true, '--no-headers' => true]));
        self::assertSame("3\tInterfaces\n4\tDisks\n", $tester->getDisplay());
        // A bad value stops the run before any list, and devices win over data queries.
        self::assertSame(1, $tester->execute(['--list-hosts' => true, '--host-id' => 'x']));
        self::assertSame(1, $tester->execute(['--list-hosts' => true, '--reindex-method' => 'bogus']));
        self::assertSame(0, $tester->execute(['--list-data-queries' => true, '--list-hosts' => true]));
        self::assertStringStartsWith('Known Devices:', $tester->getDisplay());
    }

    public function testTheOperatorNeedsTheDevicesRealmInEveryMode(): void
    {
        $this->db->executeStatement('DELETE FROM user_auth_realm WHERE realm_id = 3');
        $association = new InMemoryDataQueryAssociation();
        $tester = $this->tester($association);

        foreach ([['--host-id' => '7', '--data-query-id' => '3', '--reindex-method' => '1'], ['--list-hosts' => true], ['--list-data-queries' => true]] as $arguments) {
            self::assertSame(1, $tester->execute($arguments + ['--json' => true]));
            self::assertSame('denied', json_decode($tester->getDisplay(), true)['status']);
        }
        self::assertSame([], $association->associated);
    }

    public function testJsonReportsTheOutcomeAndBinConsoleRejectsBadInputAsInvalid(): void
    {
        $tester = $this->tester(new InMemoryDataQueryAssociation());
        self::assertSame(0, $tester->execute(['--host-id' => '7', '--data-query-id' => '3', '--reindex-method' => 'fields', '--json' => true]));
        $json = json_decode($tester->getDisplay(), true);
        self::assertSame(['associated', 7, 3, '3'], [$json['status'], $json['device']['id'], $json['data_query']['id'], $json['reindex_method']]);

        $this->presentation = new CliPresentation();
        foreach ([['--host-id' => 'x'], ['--data-query-id' => 'x'], ['--reindex-method' => 'x'], ['--host-id' => '7']] as $arguments) {
            self::assertSame(Command::INVALID, $this->tester(new InMemoryDataQueryAssociation())->execute($arguments), json_encode($arguments, JSON_THROW_ON_ERROR));
        }
    }

    public function testTheOldArgumentsAreReadAsTheScriptDidNotAsTheBaseMapDoes(): void
    {
        $arguments = new AssociateDataQueryLegacyArguments();

        self::assertSame([['--host-id' => '7', '--list-hosts' => true, '--no-headers' => true, '--reindex-method' => 'Uptime'], null], $arguments->translate(['-d', '--host-id=7', '--list-hosts=yes', '--quiet', '--reindex-method=Uptime']));
        self::assertSame([['--host-id' => ''], null], $arguments->translate(['--host-id', '--data-query-id=3']), 'a bare id is empty, and the run stops at it');
        self::assertSame([['--reindex-method' => 'bogus'], null], $arguments->translate(['--reindex-method=bogus', '--host-id=x', '--bogus']), 'the first bad value ends the run, even before an unknown flag');
        self::assertSame([['--data-query-id' => '3', '--reindex-method' => '1.5'], null], $arguments->translate(['--data-query-id=3', '--reindex-method=1.5']));
        self::assertSame(LegacyRequest::Help, $arguments->translate([])[1]);
        self::assertSame(LegacyRequest::Help, $arguments->translate(['--help', '--host-id=x'])[1]);
        self::assertSame(LegacyRequest::Version, $arguments->translate(['-d', '-V'])[1]);
        self::assertSame(['ERROR: Invalid Argument: (--bogus)', ''], $arguments->invalid('--bogus=1'));
        $this->expectException(InvalidLegacyArgument::class);
        $arguments->translate(['--bogus']);
    }

    public function testTheHelpIsTheOriginalsWordForWord(): void
    {
        $help = (new AssociateDataQueryLegacyArguments())->help();

        self::assertSame(['', 'usage: add_data_query.php --host-id=[ID] --data-query-id=[dq_id] --reindex-method=[method] [--quiet]', ''], array_slice($help, 0, 3));
        self::assertSame(['If the data query was already associated, it will be reindexed.', ''], array_slice($help, -2));
        self::assertContains('                      3|Fields = Verify all Fields', $help);
    }
}
