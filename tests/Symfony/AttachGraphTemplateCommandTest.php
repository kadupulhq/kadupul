<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Doctrine\DBAL\Connection;
use Kadupul\Graphing\Application\Command\AttachGraphTemplate;
use Kadupul\Graphing\Application\Command\GraphCommandAccess;
use Kadupul\Graphing\Application\Command\ListAttachmentChoices;
use Kadupul\Graphing\Infrastructure\Symfony\AttachGraphTemplateCommand;
use Kadupul\Graphing\Infrastructure\Symfony\AttachGraphTemplateLegacyArguments;
use Kadupul\IdentityAccess\Contract\AuditTrail;
use Kadupul\IdentityAccess\Contract\CollectorTopology;
use Kadupul\IdentityAccess\Infrastructure\Cli\CliConsoleAccess;
use Kadupul\Platform\Infrastructure\Legacy\InstallationVersion;
use Kadupul\Platform\Infrastructure\Symfony\Console\CliPresentation;
use Kadupul\Platform\Infrastructure\Symfony\Console\InvalidLegacyArgument;
use Kadupul\Platform\Infrastructure\Symfony\Console\LegacyRequest;
use Kadupul\Platform\Infrastructure\Symfony\Console\ResultRenderer;
use Kadupul\Tests\Fixtures\ConsoleOperatorDatabase;
use Kadupul\Tests\Fixtures\InMemoryGraphTemplateAssignment;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;

final class AttachGraphTemplateCommandTest extends TestCase
{
    use ConsoleOperatorDatabase;

    private string $root;
    private Connection $db;
    private CliPresentation $presentation;

    protected function setUp(): void
    {
        $this->root = $this->installationRoot('kadupul-add-graph-template-command-');
        $this->db = $this->operatorDatabase(8, 3);
        $this->presentation = new CliPresentation();
        $this->presentation->forLegacy(LegacyRequest::Run);
    }

    private function tester(InMemoryGraphTemplateAssignment $assignment): CommandTester
    {
        $access = new GraphCommandAccess(new CliConsoleAccess($this->db, $this->db), $this->createStub(CollectorTopology::class), $this->createStub(AuditTrail::class));
        $version = new InstallationVersion($this->root, $this->db, new Filesystem(), new MockClock('2031-06-01 00:00:00'));
        $command = new AttachGraphTemplateCommand(new AttachGraphTemplate($access, $assignment, $assignment), new ListAttachmentChoices($access, $assignment), $version, $this->presentation, new ResultRenderer());

        return new CommandTester(new Command(null, $command));
    }

    public function testSuccessEchoesTheIdsAsTyped(): void
    {
        $assignment = new InMemoryGraphTemplateAssignment();
        $tester = $this->tester($assignment);

        self::assertSame(0, $tester->execute(['--host-id' => ' 7e0 ', '--graph-template-id' => '3']));
        self::assertSame("Success: Graph Template associated for host: (7e0: core1) - graph-template: (3: Traffic)\n", $tester->getDisplay());
        self::assertSame([[7, 3]], $assignment->attached);
    }

    public function testEachRefusalPrintsTheOriginalLineAndExitsOne(): void
    {
        $assignment = new InMemoryGraphTemplateAssignment();
        $tester = $this->tester($assignment);
        $cases = [
            [['--host-id' => '8', '--graph-template-id' => '3'], "ERROR: Unknown Host Id (8)\n"],
            [['--host-id' => '7', '--graph-template-id' => '99'], "ERROR: Unknown Graph Template Id (99)\n"],
            [['--host-id' => '7', '--graph-template-id' => '4'], "ERROR: Graph Template is already associated for host: (7: core1) - graph-template: (4: Errors)\n"],
            [['--host-id' => 'x', '--graph-template-id' => '3'], "ERROR: You must supply a valid host-id to run this script!\n"],
            [['--host-id' => '7', '--graph-template-id' => ' x'], "ERROR: You must supply a numeric graph-template-id for all hosts!\n"],
            [['--graph-template-id' => '3'], "ERROR: You must supply a valid host-id for all hosts!\n"],
            [['--host-id' => '7'], "ERROR: You must supply a valid data-query-id for all hosts!\n"],
        ];
        foreach ($cases as [$arguments, $expected]) {
            self::assertSame(1, $tester->execute($arguments), json_encode($arguments, JSON_THROW_ON_ERROR));
            self::assertSame($expected, $tester->getDisplay());
        }
        self::assertSame([], $assignment->attached);
    }

    public function testAFailedWorkerPrintsTheFailureLine(): void
    {
        $tester = $this->tester(new InMemoryGraphTemplateAssignment(fails: true));

        self::assertSame(1, $tester->execute(['--host-id' => '7', '--graph-template-id' => '3']));
        self::assertSame("ERROR: Failed to add this graph template for host: (7: core1) - graph-template: (3: Traffic)\n", $tester->getDisplay());
    }

    public function testTheListModesPrintTheirTablesAndQuietDropsTheFrame(): void
    {
        $tester = $this->tester(new InMemoryGraphTemplateAssignment());

        self::assertSame(0, $tester->execute(['--list-hosts' => true]));
        self::assertSame("Known Devices: (id, hostname, template, description)\n7\tcore1\t2\tCore router\n\n", $tester->getDisplay());
        self::assertSame(0, $tester->execute(['--list-graph-templates' => true, '--no-headers' => true]));
        self::assertSame("3\tTraffic\n4\tErrors\n", $tester->getDisplay());
        // A bad id stops the run before any list, and devices win over graph templates.
        self::assertSame(1, $tester->execute(['--list-hosts' => true, '--host-id' => 'x']));
        self::assertSame(0, $tester->execute(['--list-graph-templates' => true, '--list-hosts' => true]));
        self::assertStringStartsWith('Known Devices:', $tester->getDisplay());
    }

    public function testTheOperatorNeedsTheDevicesRealmInEveryMode(): void
    {
        $this->db->executeStatement('DELETE FROM user_auth_realm WHERE realm_id = 3');
        $assignment = new InMemoryGraphTemplateAssignment();
        $tester = $this->tester($assignment);

        foreach ([['--host-id' => '7', '--graph-template-id' => '3'], ['--list-hosts' => true], ['--list-graph-templates' => true]] as $arguments) {
            self::assertSame(1, $tester->execute($arguments + ['--json' => true]));
            self::assertSame('denied', json_decode($tester->getDisplay(), true)['status']);
        }
        self::assertSame([], $assignment->attached);
    }

    public function testJsonReportsTheOutcomeAndBinConsoleRejectsBadInputAsInvalid(): void
    {
        $tester = $this->tester(new InMemoryGraphTemplateAssignment());
        self::assertSame(0, $tester->execute(['--host-id' => '7', '--graph-template-id' => '3', '--json' => true]));
        $json = json_decode($tester->getDisplay(), true);
        self::assertSame(['attached', 7, 3], [$json['status'], $json['device']['id'], $json['graph_template']['id']]);

        $this->presentation = new CliPresentation();
        self::assertSame(Command::INVALID, $this->tester(new InMemoryGraphTemplateAssignment())->execute(['--host-id' => 'x']));
    }

    public function testTheOldArgumentsAreReadAsGetoptDidNotAsTheBaseMapDoes(): void
    {
        $arguments = new AttachGraphTemplateLegacyArguments();

        self::assertSame([['--host-id' => '7', '--list-hosts' => true, '--no-headers' => true], null], $arguments->translate(['-d', '--host-id=7', '--list-hosts=yes', '--quiet']));
        self::assertSame([['--host-id' => ''], null], $arguments->translate(['--host-id', '--graph-template-id=3']), 'a bare id is empty, and the run stops at it');
        self::assertSame(LegacyRequest::Help, $arguments->translate([])[1]);
        self::assertSame(LegacyRequest::Version, $arguments->translate(['-d', '-V'])[1]);
        self::assertSame(['ERROR: Invalid Argument: (--bogus)', ''], $arguments->invalid('--bogus=1'));
        $this->expectException(InvalidLegacyArgument::class);
        $arguments->translate(['--bogus']);
    }
}
