<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Doctrine\DBAL\Connection;
use Kadupul\Graphing\Application\Command\AddDataSource;
use Kadupul\Graphing\Application\Command\GraphCommandAccess;
use Kadupul\Graphing\Infrastructure\Symfony\AddDataSourceCommand;
use Kadupul\Graphing\Infrastructure\Symfony\AddDataSourceLegacyArguments;
use Kadupul\IdentityAccess\Contract\AuditTrail;
use Kadupul\IdentityAccess\Contract\CollectorTopology;
use Kadupul\IdentityAccess\Infrastructure\Cli\CliConsoleAccess;
use Kadupul\Platform\Infrastructure\Legacy\InstallationVersion;
use Kadupul\Platform\Infrastructure\Symfony\Console\CliPresentation;
use Kadupul\Platform\Infrastructure\Symfony\Console\InvalidLegacyArgument;
use Kadupul\Platform\Infrastructure\Symfony\Console\LegacyRequest;
use Kadupul\Platform\Infrastructure\Symfony\Console\ResultRenderer;
use Kadupul\Tests\Fixtures\ConsoleOperatorDatabase;
use Kadupul\Tests\Fixtures\InMemoryDataSourceCreation;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;

final class AddDataSourceCommandTest extends TestCase
{
    use ConsoleOperatorDatabase;

    private string $root;
    private Connection $db;
    private CliPresentation $presentation;

    protected function setUp(): void
    {
        $this->root = $this->installationRoot('kadupul-add-data-source-command-');
        $this->db = $this->operatorDatabase(8, 3);
        $this->presentation = new CliPresentation();
        $this->presentation->forLegacy(LegacyRequest::Run);
    }

    private function tester(InMemoryDataSourceCreation $creation): CommandTester
    {
        $access = new GraphCommandAccess(new CliConsoleAccess($this->db, $this->db), $this->createStub(CollectorTopology::class), $this->createStub(AuditTrail::class));
        $version = new InstallationVersion($this->root, $this->db, new Filesystem(), new MockClock('2031-06-01 00:00:00'));
        $command = new AddDataSourceCommand(new AddDataSource($access, $creation, $creation), $version, $this->presentation, new ResultRenderer());

        return new CommandTester(new Command(null, $command));
    }

    public function testSuccessPrintsTheNewIdAndPushesOutUnlessTheDeviceIsTheTextZero(): void
    {
        $creation = new InMemoryDataSourceCreation();
        $tester = $this->tester($creation);

        self::assertSame(0, $tester->execute(['--host-id' => ' 7e0 ', '--data-template-id' => '3']));
        self::assertSame("DS Added - DS[41]\n", $tester->getDisplay());
        self::assertSame(0, $tester->execute(['--host-id' => '0', '--data-template-id' => ' 3']));
        self::assertSame(0, $tester->execute(['--host-id' => '0.0', '--data-template-id' => '0']));
        self::assertSame([[7, 3, true], [0, 3, false], [0, 0, true]], $creation->created);
    }

    public function testEachRefusalPrintsTheOriginalLineAndExitsOne(): void
    {
        $creation = new InMemoryDataSourceCreation();
        $tester = $this->tester($creation);
        $ok = ['--host-id' => '7', '--data-template-id' => '3'];
        $cases = [
            [['--host-id' => '8'] + $ok, "ERROR: Unknown Host Id (8)\n"],
            [['--data-template-id' => '99'] + $ok, "ERROR: Unknown Data Template Id (99)\n"],
            [['--host-id' => 'x'] + $ok, "ERROR: You must supply a valid host-id to run this script!\n"],
            [['--host-id' => ''] + $ok, "ERROR: You must supply a valid host-id to run this script!\n"],
            [['--data-template-id' => ' x'] + $ok, "ERROR: You must supply a numeric data-template-id!\n"],
            [['--data-template-id' => ''] + $ok, "ERROR: You must supply a numeric data-template-id!\n"],
            [array_diff_key($ok, ['--host-id' => 1]), "ERROR: You must supply a valid host-id!\n"],
            [array_diff_key($ok, ['--data-template-id' => 1]), "ERROR: You must supply a valid data-template-id!\n"],
            [[], "ERROR: You must supply a valid host-id!\n"],
        ];
        foreach ($cases as [$arguments, $expected]) {
            self::assertSame(1, $tester->execute($arguments), json_encode($arguments, JSON_THROW_ON_ERROR));
            self::assertSame($expected, $tester->getDisplay());
        }
        self::assertSame([], $creation->created);
    }

    public function testAFailedWorkerPrintsTheFailureLine(): void
    {
        $tester = $this->tester(new InMemoryDataSourceCreation(fails: true));

        self::assertSame(1, $tester->execute(['--host-id' => '7', '--data-template-id' => '3']));
        self::assertSame("ERROR: Failed to add the data source for host (7) data template (3)\n", $tester->getDisplay());
    }

    public function testTheOperatorNeedsTheDevicesRealm(): void
    {
        $this->db->executeStatement('DELETE FROM user_auth_realm WHERE realm_id = 3');
        $creation = new InMemoryDataSourceCreation();
        $tester = $this->tester($creation);

        self::assertSame(1, $tester->execute(['--host-id' => '7', '--data-template-id' => '3', '--json' => true]));
        self::assertSame('denied', json_decode($tester->getDisplay(), true)['status']);
        self::assertSame([], $creation->created);
    }

    public function testJsonReportsTheOutcomeAndBinConsoleRejectsBadInputAsInvalid(): void
    {
        $tester = $this->tester(new InMemoryDataSourceCreation());
        self::assertSame(0, $tester->execute(['--host-id' => '7', '--data-template-id' => '3', '--json' => true]));
        $json = json_decode($tester->getDisplay(), true);
        self::assertSame(['added', 41, 7, 3], [$json['status'], $json['data_source_id'], $json['device']['id'], $json['data_template']['id']]);
        self::assertSame(1, $tester->execute(['--host-id' => '8', '--data-template-id' => '3', '--json' => true]));
        $json = json_decode($tester->getDisplay(), true);
        self::assertSame(['unknown_device', null, null], [$json['status'], $json['data_source_id'], $json['device']]);

        $this->presentation = new CliPresentation();
        foreach ([['--host-id' => 'x'], ['--data-template-id' => 'x'], ['--host-id' => '7'], ['--data-template-id' => '3']] as $arguments) {
            self::assertSame(Command::INVALID, $this->tester(new InMemoryDataSourceCreation())->execute($arguments), json_encode($arguments, JSON_THROW_ON_ERROR));
        }
    }

    public function testTheOldArgumentsAreReadAsTheScriptDidNotAsTheBaseMapDoes(): void
    {
        $arguments = new AddDataSourceLegacyArguments();

        self::assertSame([['--host-id' => '7', '--data-template-id' => '3'], null], $arguments->translate(['--host-id=7', '--data-template-id=3']));
        self::assertSame([['--host-id' => ''], null], $arguments->translate(['--host-id', '--data-template-id=3']), 'a bare id is empty, and the run stops at it');
        self::assertSame([['--data-template-id' => 'x'], null], $arguments->translate(['--data-template-id=x', '--host-id=y', '--bogus']), 'the first bad value ends the run, even before an unknown flag');
        self::assertSame([['--host-id' => ' 7 ', '--data-template-id' => ' 3'], null], $arguments->translate(['--host-id= 7 ', '--data-template-id= 3']), 'the device id is trimmed to test it, and sent as typed');
        self::assertSame([['--data-template-id' => ' 3 '], null], $arguments->translate(['--data-template-id= 3 ']));
        self::assertSame([[], null], $arguments->translate([]), 'no arguments is not a help request');
        self::assertSame(LegacyRequest::Help, $arguments->translate(['--help', '--host-id=x'])[1]);
        self::assertSame(LegacyRequest::Version, $arguments->translate(['-V', '--bogus'])[1]);
        self::assertSame(LegacyRequest::Help, $arguments->translate(['-h=1'])[1]);
        self::assertSame(['ERROR: Invalid Parameter --bogus=1', ''], $arguments->invalid('--bogus=1'));
        foreach (['-d', '--quiet', '--list-hosts', '=1'] as $unknown) {
            try {
                $arguments->translate([$unknown]);
                self::fail($unknown . ' was accepted.');
            } catch (InvalidLegacyArgument) {
                self::assertTrue(true);
            }
        }
    }

    public function testTheHelpIsTheOriginalsWordForWord(): void
    {
        self::assertSame([
            'usage: add_datasource.php --host-id=[ID] --data-template-id=[ID]', '',
            'Kadupul utility for adding datasources via a command line interface.', '',
            '--host-id=id - The host id',
            '--data-template-id=id - The numerical ID of the data template to be added',
        ], (new AddDataSourceLegacyArguments())->help());
    }
}
