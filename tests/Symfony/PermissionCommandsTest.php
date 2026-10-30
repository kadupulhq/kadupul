<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Doctrine\DBAL\Connection;
use Kadupul\IdentityAccess\Application\Command\GrantItemPermission;
use Kadupul\IdentityAccess\Application\Command\ListPermissionTargets;
use Kadupul\IdentityAccess\Application\Command\UserCommandAccess;
use Kadupul\IdentityAccess\Application\Port\PermissionCatalog;
use Kadupul\IdentityAccess\Contract\AuditTrail;
use Kadupul\IdentityAccess\Contract\CollectorTopology;
use Kadupul\IdentityAccess\Domain\ListTarget;
use Kadupul\IdentityAccess\Infrastructure\Cli\CliConsoleAccess;
use Kadupul\IdentityAccess\Infrastructure\Symfony\AddPermissionCommand;
use Kadupul\IdentityAccess\Infrastructure\Symfony\AddPermsLegacyArguments;
use Kadupul\IdentityAccess\Infrastructure\Symfony\ListPermissionsCommand;
use Kadupul\Platform\Infrastructure\Legacy\InstallationVersion;
use Kadupul\Platform\Infrastructure\Symfony\Console\CliPresentation;
use Kadupul\Platform\Infrastructure\Symfony\Console\InvalidLegacyArgument;
use Kadupul\Platform\Infrastructure\Symfony\Console\LegacyArguments;
use Kadupul\Platform\Infrastructure\Symfony\Console\LegacyRequest;
use Kadupul\Platform\Infrastructure\Symfony\Console\ResultRenderer;
use Kadupul\Tests\Fixtures\ConsoleOperatorDatabase;
use Kadupul\Tests\Fixtures\InMemoryItemPermissions;
use Kadupul\Tests\Fixtures\InMemoryPermissionCatalog;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;

final class PermissionCommandsTest extends TestCase
{
    use ConsoleOperatorDatabase;

    private const string VERSION = 'Kadupul Add Permissions Utility, Version 1.3.0 (DB: 1.3.0), Copyright (C) 2004-2031 The Cacti Group';

    private string $root;
    private Connection $db;
    private CliPresentation $presentation;
    private InMemoryItemPermissions $permissions;

    protected function setUp(): void
    {
        $this->root = $this->installationRoot('kadupul-permission-command-');
        // User 1, admin, holds Console Access (8) and User Management (1).
        $this->db = $this->operatorDatabase(8, 1);
        $this->presentation = new CliPresentation();
        $this->permissions = new InMemoryItemPermissions();
    }

    private function access(): UserCommandAccess
    {
        return new UserCommandAccess(new CliConsoleAccess($this->db, $this->db), $this->createStub(CollectorTopology::class), $this->createStub(AuditTrail::class));
    }

    private function version(): InstallationVersion
    {
        return new InstallationVersion($this->root, $this->db, new Filesystem(), new MockClock('2031-06-01 00:00:00'));
    }

    private function add(): CommandTester
    {
        return new CommandTester(new Command(null, new AddPermissionCommand(new GrantItemPermission($this->access(), $this->permissions), $this->version(), $this->presentation, new ResultRenderer())));
    }

    private function list(): CommandTester
    {
        $list = new ListPermissionTargets($this->access(), $this->permissions, new InMemoryPermissionCatalog());

        return new CommandTester(new Command(null, new ListPermissionsCommand($list, $this->version(), $this->presentation, new ResultRenderer())));
    }

    private function help(): string
    {
        return self::VERSION . "\n\nusage: add_perms.php [ --user-id=[ID] ]\n    --item-type=[graph|tree|host|graph_template]\n    --item-id [--quiet]\n\n"
            . "Where item-id is the id of the object of type item-type\n\nList Options:\n    --list-users\n    --list-trees\n    --list-graph-templates\n    --list-graphs --host-id=[ID]\n";
    }

    public function testLegacyGrantIsSilentAndExitsZero(): void
    {
        $this->presentation->forLegacy(LegacyRequest::Run);
        $tester = $this->add();

        self::assertSame(0, $tester->execute(['--user-id' => '7', '--item-type' => 'host', '--item-id' => '5']));
        self::assertSame('', $tester->getDisplay());
        self::assertSame(['Local:7:3:5'], $this->permissions->grants);
    }

    public function testLegacyWithoutAUserChecksTheItemAndGrantsNothing(): void
    {
        $this->presentation->forLegacy(LegacyRequest::Run);
        $tester = $this->add();

        self::assertSame(0, $tester->execute(['--item-type' => 'host', '--item-id' => '5']));
        self::assertSame('', $tester->getDisplay());
        self::assertSame([], $this->permissions->grants);
    }

    /** @return iterable<string, array{array<string, string>, string}> */
    public static function legacyRefusals(): iterable
    {
        yield 'unknown user' => [['--user-id' => '8', '--item-type' => 'host', '--item-id' => '5'], 'ERROR: Invalid Userid: (8)'];
        yield 'user that is not a number' => [['--user-id' => 'abc', '--item-type' => 'host', '--item-id' => '5'], 'ERROR: Invalid Userid: (abc)'];
        yield 'missing item type' => [['--user-id' => '7', '--item-id' => '5'], 'ERROR: --item-type missing. Please specify.'];
        yield 'missing item id' => [['--user-id' => '7', '--item-type' => 'host'], 'ERROR: --item-id missing. Please specify.'];
        yield 'unknown device' => [['--user-id' => '7', '--item-type' => 'host', '--item-id' => '6'], 'ERROR: Invalid Host item id: (6)'];
        yield 'unknown graph template' => [['--user-id' => '7', '--item-type' => 'graph_template', '--item-id' => '5'], 'ERROR: Invalid Graph Template item id: (5)'];
    }

    /** @param array<string, string> $input */
    #[DataProvider('legacyRefusals')]
    public function testLegacyRefusalsPrintTheErrorAndTheHelpAndExitOne(array $input, string $error): void
    {
        $this->presentation->forLegacy(LegacyRequest::Run);
        $tester = $this->add();

        self::assertSame(1, $tester->execute($input));
        self::assertSame($error . "\n\n" . $this->help(), $tester->getDisplay());
        self::assertSame([], $this->permissions->grants);
    }

    public function testALegacyFailedWriteIsNoLongerReportedAsSuccess(): void
    {
        $this->presentation->forLegacy(LegacyRequest::Run);
        $this->permissions = new InMemoryItemPermissions(result: false);
        $tester = $this->add();

        self::assertSame(1, $tester->execute(['--user-id' => '7', '--item-type' => 'host', '--item-id' => '5']));
        self::assertSame("ERROR: Permission not granted\n", $tester->getDisplay());
    }

    public function testJsonReportsTheGrantAndEveryRefusalHasAStatus(): void
    {
        $tester = $this->add();

        self::assertSame(0, $tester->execute(['--user-id' => '7', '--item-type' => 'host', '--item-id' => '5', '--json' => true]));
        self::assertSame(['status' => 'ok', 'database' => 'local', 'dry_run' => false, 'result' => 'granted', 'user_id' => '7', 'item_type' => 'host', 'item_id' => '5'], json_decode($tester->getDisplay(), true));

        self::assertSame(Command::INVALID, $tester->execute(['--user-id' => '7', '--item-type' => 'host', '--item-id' => '6', '--json' => true]));
        self::assertSame(['invalid', 'item-invalid'], [json_decode($tester->getDisplay(), true)['status'], json_decode($tester->getDisplay(), true)['result']]);

        $this->permissions = new InMemoryItemPermissions(result: false);
        self::assertSame(Command::FAILURE, $this->add()->execute(['--user-id' => '7', '--item-type' => 'host', '--item-id' => '5', '--json' => true]));
    }

    public function testOutsideTheShimAUserIsRequired(): void
    {
        $tester = $this->add();

        self::assertSame(Command::INVALID, $tester->execute(['--item-type' => 'host', '--item-id' => '5', '--json' => true]));
        self::assertSame('The --user-id option is required.', json_decode($tester->getDisplay(), true)['error']);
        self::assertSame(Command::INVALID, $tester->execute(['--user-id' => '7', '--item-type' => 'bogus', '--item-id' => '5', '--json' => true]));
        self::assertSame([], $this->permissions->grants);
    }

    public function testADryRunGrantsNothing(): void
    {
        $tester = $this->add();

        self::assertSame(0, $tester->execute(['--user-id' => '7', '--item-type' => 'host', '--item-id' => '5', '--dry-run' => true]));
        self::assertStringContainsString('Would grant', $tester->getDisplay());
        self::assertSame([], $this->permissions->grants);
    }

    public function testAnOperatorWithoutUserManagementIsDeniedForBothCommands(): void
    {
        $this->db->executeStatement('DELETE FROM user_auth_realm WHERE realm_id = 1');

        self::assertSame(1, $this->add()->execute(['--user-id' => '7', '--item-type' => 'host', '--item-id' => '5', '--json' => true]));
        self::assertSame(1, $this->list()->execute(['target' => 'users', '--json' => true]));
        self::assertSame([], $this->permissions->grants);
    }

    public function testLegacyUsageHelpAndVersion(): void
    {
        $tester = $this->add();
        $this->presentation->forLegacy(LegacyRequest::Usage);
        self::assertSame(1, $tester->execute([]));
        self::assertSame($this->help(), $tester->getDisplay());

        $this->presentation->forLegacy(LegacyRequest::Version);
        self::assertSame(0, $tester->execute([]));
        self::assertSame(self::VERSION . "\n", $tester->getDisplay());
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function legacyListings(): iterable
    {
        yield 'users' => [['target' => 'users'], "Known Users: (id, username, full_name)\n1\tadmin\tAdministrator\n7\tops\t\n\n"];
        yield 'users quietly' => [['target' => 'users', '--no-headings' => true], "1\tadmin\tAdministrator\n7\tops\t\n"];
        yield 'trees with a label, and none for an unknown sort' => [['target' => 'trees'], "Known Trees: (id, sort method, name)\n1\tAlphabetic Ordering\tDefault Tree\n2\t\tOdd\n\n"];
        yield 'devices' => [['target' => 'hosts'], "Known Devices: (id, hostname, template, description)\n5\trouter.example\t3\tRouter\n\n"];
        yield 'graph templates' => [['target' => 'graph-templates'], "Known Graph Templates: (id, name)\n4\tInterface - Traffic\n\n"];
        yield 'graphs end each row with a tab' => [['target' => 'graphs', '--host-id' => '5'], "Known Device Graphs: (id, name, template)\n9\tTraffic\tInterface\t\n\n"];
        yield 'groups announce what was never built, without a newline' => [['target' => 'groups'], 'This option has not yet been implemented'];
    }

    /** @param array<string, mixed> $input */
    #[DataProvider('legacyListings')]
    public function testLegacyListingsPrintTheOriginalLines(array $input, string $expected): void
    {
        $this->presentation->forLegacy(LegacyRequest::Run);
        $tester = $this->list();

        self::assertSame(0, $tester->execute($input));
        self::assertSame($expected, $tester->getDisplay());
    }

    public function testLegacyEmptyListingsKeepTheirHeadingAndClosingBlankLine(): void
    {
        $this->presentation->forLegacy(LegacyRequest::Run);
        // A stub returns an empty array for every listing.
        $list = new ListPermissionTargets($this->access(), $this->permissions, $this->createStub(PermissionCatalog::class));
        $tester = new CommandTester(new Command(null, new ListPermissionsCommand($list, $this->version(), $this->presentation, new ResultRenderer())));

        self::assertSame(0, $tester->execute(['target' => 'trees']));
        self::assertSame("Known Trees: (id, sort method, name)\n\n", $tester->getDisplay());
        self::assertSame(0, $tester->execute(['target' => 'users', '--no-headings' => true]));
        self::assertSame('', $tester->getDisplay());
    }

    public function testAnEmptyHumanListingSaysSo(): void
    {
        $list = new ListPermissionTargets($this->access(), new InMemoryItemPermissions(items: ['3:6']), new InMemoryPermissionCatalog());
        $tester = new CommandTester(new Command(null, new ListPermissionsCommand($list, $this->version(), $this->presentation, new ResultRenderer())));

        // Device 6 exists but has no graphs.
        self::assertSame(0, $tester->execute(['target' => 'graphs', '--host-id' => '6']));
        self::assertStringContainsString('Nothing to list.', $tester->getDisplay());
    }

    public function testLegacyGraphsOfAMissingDevicePrintTheErrorAndTheHelp(): void
    {
        $this->presentation->forLegacy(LegacyRequest::Run);
        $tester = $this->list();

        foreach ([['target' => 'graphs'], ['target' => 'graphs', '--host-id' => '6'], ['target' => 'graphs', '--host-id' => 'abc']] as $input) {
            self::assertSame(1, $tester->execute($input));
            self::assertSame("ERROR: You must supply a valid host_id before you can list its graphs\nTry --list-hosts\n" . $this->help(), $tester->getDisplay());
        }
    }

    public function testJsonAndHumanListings(): void
    {
        $tester = $this->list();

        self::assertSame(0, $tester->execute(['target' => 'users', '--json' => true]));
        self::assertSame([['id' => '1', 'username' => 'admin', 'full_name' => 'Administrator'], ['id' => '7', 'username' => 'ops', 'full_name' => '']], json_decode($tester->getDisplay(), true)['rows']);

        self::assertSame(0, $tester->execute(['target' => 'hosts']));
        self::assertStringContainsString('router.example', $tester->getDisplay());

        self::assertSame(Command::INVALID, $tester->execute(['target' => 'graphs', '--host-id' => '6', '--json' => true]));
        self::assertSame(Command::FAILURE, $tester->execute(['target' => 'groups', '--json' => true]));
        self::assertSame(Command::INVALID, $tester->execute(['target' => 'bogus', '--json' => true]));
        self::assertSame(Command::INVALID, $tester->execute(['--json' => true]));
    }

    /** @return iterable<string, array{list<string>, array<string, mixed>, ?LegacyRequest}> */
    public static function translations(): iterable
    {
        yield 'a grant' => [['--user-id=7', '--item-type=graph_template', '--item-id=4'], ['--user-id' => '7', '--item-type' => 'graph_template', '--item-id' => '4'], null];
        yield 'the shim operator' => [['--user-id=7', '--as=ops'], ['--user-id' => '7', '--as' => 'ops'], null];
        yield 'a bare --user-id is an empty value' => [['--user-id', '--item-id=5'], ['--user-id' => '', '--item-id' => '5'], null];
        yield 'the quiet flag ignores a value' => [['--list-users', '--quiet=no'], ['target' => 'users', '--no-headings' => true], null];
        yield 'a device for graphs' => [['--list-graphs', '--host-id=5'], ['target' => 'graphs', '--host-id' => '5'], null];
        yield 'a listing drops the grant arguments' => [['--item-id=5', '--list-hosts', '--user-id=7'], ['target' => 'hosts'], null];
        yield 'the first listing in the original order wins' => [['--list-graph-templates', '--list-trees', '--list-users', '--list-groups'], ['target' => 'groups'], null];
        yield 'no arguments' => [[], [], LegacyRequest::Usage];
        yield 'help ends the parse' => [['-H', '--bogus'], [], LegacyRequest::Help];
        yield 'version ends the parse' => [['--user-id=7', '-V', '--bogus'], [], LegacyRequest::Version];
    }

    /**
     * @param list<string> $argv
     * @param array<string, mixed> $input
     */
    #[DataProvider('translations')]
    public function testTheArgumentMapReadsTheOriginalGrammar(array $argv, array $input, ?LegacyRequest $special): void
    {
        self::assertSame([$input, $special], (new AddPermsLegacyArguments())->translate($argv));
    }

    /** @return iterable<string, array{list<string>, list<string>}> */
    public static function rejections(): iterable
    {
        yield 'an unknown flag' => [['--bogus'], ['ERROR: Invalid Argument: (--bogus)', '']];
        yield 'an unknown flag reports the flag, not its value' => [['--bogus=1'], ['ERROR: Invalid Argument: (--bogus)', '']];
        yield 'an unknown item type' => [['--item-type=user'], ['ERROR: Invalid Item Type: (user)', '']];
        yield 'an empty item type' => [['--item-type='], ['ERROR: Invalid Item Type: ()', '']];
        yield 'an empty shim operator' => [['--as='], ['ERROR: Invalid Argument: (--as)', '']];
        yield 'an equals sign first is no flag' => [['=x'], ['ERROR: Invalid Argument: ()', '']];
    }

    /**
     * @param list<string> $argv
     * @param list<string> $lines
     */
    #[DataProvider('rejections')]
    public function testTheArgumentMapRejectsWhatTheOriginalRejected(array $argv, array $lines): void
    {
        $map = new AddPermsLegacyArguments();
        try {
            $map->translate($argv);
            self::fail('The argument was accepted.');
        } catch (InvalidLegacyArgument $invalid) {
            self::assertSame($lines, $map->invalid($invalid->argument));
        }
    }

    public function testOnlyAListingIsRoutedToTheListCommand(): void
    {
        $map = new AddPermsLegacyArguments();

        self::assertSame('kadupul:permission:list', $map->route(['target' => 'users'], 'kadupul:permission:add'));
        self::assertSame('kadupul:permission:add', $map->route(['--user-id' => '7'], 'kadupul:permission:add'));
        self::assertSame('kadupul:x', (new class extends LegacyArguments {
            protected function flags(): array
            {
                return [];
            }

            public function help(): array
            {
                return [];
            }
        })->route([], 'kadupul:x'), 'a script with one command keeps it');
        self::assertCount(6, ListTarget::cases());
    }
}
