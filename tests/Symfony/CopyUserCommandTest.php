<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Doctrine\DBAL\Connection;
use Kadupul\IdentityAccess\Application\Command\CopyUser;
use Kadupul\IdentityAccess\Application\Command\UserCommandAccess;
use Kadupul\IdentityAccess\Contract\AuditTrail;
use Kadupul\IdentityAccess\Contract\CollectorTopology;
use Kadupul\IdentityAccess\Infrastructure\Cli\CliConsoleAccess;
use Kadupul\IdentityAccess\Infrastructure\Symfony\CopyUserCommand;
use Kadupul\IdentityAccess\Infrastructure\Symfony\CopyUserLegacyArguments;
use Kadupul\Platform\Infrastructure\Legacy\InstallationVersion;
use Kadupul\Platform\Infrastructure\Symfony\Console\CliPresentation;
use Kadupul\Platform\Infrastructure\Symfony\Console\InvalidLegacyArgument;
use Kadupul\Platform\Infrastructure\Symfony\Console\LegacyRequest;
use Kadupul\Platform\Infrastructure\Symfony\Console\ResultRenderer;
use Kadupul\Tests\Fixtures\ConsoleOperatorDatabase;
use Kadupul\Tests\Fixtures\InMemoryUserCopier;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;

final class CopyUserCommandTest extends TestCase
{
    use ConsoleOperatorDatabase;

    private string $root;
    private Connection $db;
    private CliPresentation $presentation;

    protected function setUp(): void
    {
        $this->root = $this->installationRoot('kadupul-copy-user-command-');
        // User 1, admin, holds Console Access (8) and User Management (1).
        $this->db = $this->operatorDatabase(8, 1);
        $this->presentation = new CliPresentation();
    }

    private function tester(InMemoryUserCopier $copier): CommandTester
    {
        $topology = $this->createStub(CollectorTopology::class);
        $access = new UserCommandAccess(new CliConsoleAccess($this->db, $this->db), $topology, $this->createStub(AuditTrail::class));
        $version = new InstallationVersion($this->root, $this->db, new Filesystem(), new MockClock('2031-06-01 00:00:00'));
        $command = new CopyUserCommand(new CopyUser($access, $copier), $version, $this->presentation, new ResultRenderer());

        return new CommandTester(new Command(null, $command));
    }

    private function versionLine(): string
    {
        return 'Kadupul Copy User Utility, Version 1.3.0 (DB: 1.3.0), Copyright (C) 2004-2031 The Cacti Group';
    }

    private function help(): string
    {
        return $this->versionLine() . "\n"
            . "usage: copy_user.php <template user> <new user>\n\n"
            . "A utility to copy on local Kadupul user and their settings to a new one.\n\n"
            . "NOTE: It is highly recommended that you use the web interface to copy users as\n"
            . "this script will only copy Local Kadupul users.\n\n";
    }

    public function testLegacyCopyPrintsTheOriginalLines(): void
    {
        $this->presentation->forLegacy(LegacyRequest::Run);
        $copier = new InMemoryUserCopier(['tpl']);
        $tester = $this->tester($copier);

        self::assertSame(0, $tester->execute(['template' => 'tpl', 'new' => 'bob']));
        self::assertSame("Template User: tpl\nNew User:      bob\n\nCopying User...\nUser copied...\n", $tester->getDisplay());
        self::assertSame(['Local:tpl>bob'], $copier->copies);
    }

    /** @return iterable<string, array{InMemoryUserCopier}> */
    public static function refusals(): iterable
    {
        yield 'template missing' => [new InMemoryUserCopier([])];
        yield 'new user exists' => [new InMemoryUserCopier(['tpl', 'bob'])];
        yield 'copy fails' => [new InMemoryUserCopier(['tpl'], false, false)];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('refusals')]
    public function testLegacyFailuresPrintTheSameLineAndExitZero(InMemoryUserCopier $copier): void
    {
        $this->presentation->forLegacy(LegacyRequest::Run);
        $tester = $this->tester($copier);

        self::assertSame(0, $tester->execute(['template' => 'tpl', 'new' => 'bob']));
        self::assertSame("Template User: tpl\nNew User:      bob\n\nCopying User...\nError: User not copied!\n\n", $tester->getDisplay());
    }

    public function testLegacyUsageExitsOneAndHelpAndVersionExitZero(): void
    {
        $tester = $this->tester(new InMemoryUserCopier([]));
        $this->presentation->forLegacy(LegacyRequest::Usage);
        self::assertSame(1, $tester->execute([]));
        self::assertSame($this->help(), $tester->getDisplay());

        $this->presentation->forLegacy(LegacyRequest::Help);
        self::assertSame(0, $tester->execute([]));
        self::assertSame($this->help(), $tester->getDisplay());

        $this->presentation->forLegacy(LegacyRequest::Version);
        self::assertSame(0, $tester->execute([]));
        self::assertSame($this->versionLine() . "\n", $tester->getDisplay());
    }

    public function testJsonReportsTheOutcomeAndExitsOneOnFailure(): void
    {
        $tester = $this->tester(new InMemoryUserCopier(['tpl']));
        self::assertSame(0, $tester->execute(['template' => 'tpl', 'new' => 'bob', '--json' => true]));
        self::assertSame(['status' => 'ok', 'database' => 'local', 'dry_run' => false, 'result' => 'copied', 'template' => 'tpl', 'new' => 'bob'], json_decode($tester->getDisplay(), true));

        self::assertSame(1, $tester->execute(['template' => 'tpl', 'new' => 'tpl', '--json' => true]));
        self::assertSame('new-user-exists', json_decode($tester->getDisplay(), true)['result']);
    }

    public function testADryRunCopiesNothing(): void
    {
        $copier = new InMemoryUserCopier(['tpl']);
        $tester = $this->tester($copier);

        self::assertSame(0, $tester->execute(['template' => 'tpl', 'new' => 'bob', '--dry-run' => true, '--json' => true]));
        $json = json_decode($tester->getDisplay(), true);
        self::assertSame(['planned', true], [$json['result'], $json['dry_run']]);
        self::assertSame([], $copier->copies);
    }

    public function testHumanOutputUsesTheConsoleStyle(): void
    {
        $tester = $this->tester(new InMemoryUserCopier(['tpl']));
        self::assertSame(0, $tester->execute(['template' => 'tpl', 'new' => 'bob']));
        self::assertStringContainsString("Copied user 'tpl' to 'bob'.", $tester->getDisplay());

        self::assertSame(1, $tester->execute(['template' => 'nope', 'new' => 'bob']));
        self::assertStringContainsString('The template user does not exist.', $tester->getDisplay());
    }

    public function testEmptyUsernamesAreInvalidBeforeAnyUseCaseRuns(): void
    {
        $copier = new InMemoryUserCopier(['tpl']);
        $tester = $this->tester($copier);

        self::assertSame(Command::INVALID, $tester->execute(['template' => 'tpl', 'new' => '', '--json' => true]));
        self::assertSame('invalid', json_decode($tester->getDisplay(), true)['status']);
        self::assertSame([], $copier->copies);
    }

    public function testAnOperatorWithoutUserManagementIsDenied(): void
    {
        $this->db->executeStatement('DELETE FROM user_auth_realm WHERE realm_id = 1');
        $copier = new InMemoryUserCopier(['tpl']);
        $tester = $this->tester($copier);

        self::assertSame(1, $tester->execute(['template' => 'tpl', 'new' => 'bob', '--json' => true]));
        self::assertSame('denied', json_decode($tester->getDisplay(), true)['status']);
        self::assertSame([], $copier->copies);
    }

    public function testAMissingUsernameIsInvalidInput(): void
    {
        $tester = $this->tester(new InMemoryUserCopier(['tpl']));

        self::assertSame(Command::INVALID, $tester->execute(['template' => 'tpl', '--json' => true]));
        self::assertSame('invalid', json_decode($tester->getDisplay(), true)['status']);
    }

    public function testAnEmptyOperatorNameIsAUsageError(): void
    {
        $tester = $this->tester(new InMemoryUserCopier(['tpl']));
        self::assertSame(Command::INVALID, $tester->execute(['template' => 'tpl', 'new' => 'bob', '--as' => '', '--json' => true]));
    }

    /** @return iterable<string, array{list<string>, array<string, mixed>, ?LegacyRequest}> */
    public static function translations(): iterable
    {
        yield 'two usernames' => [['tpl', 'bob'], ['template' => 'tpl', 'new' => 'bob'], null];
        yield 'extra arguments are ignored' => [['tpl', 'bob', 'junk'], ['template' => 'tpl', 'new' => 'bob'], null];
        yield 'shim operator' => [['tpl', 'bob', '--as=ops'], ['template' => 'tpl', 'new' => 'bob', '--as' => 'ops'], null];
        yield 'no arguments' => [[], [], LegacyRequest::Usage];
        yield 'a zero first argument counts as none' => [['0', 'bob'], [], LegacyRequest::Usage];
        yield 'missing new user' => [['tpl'], [], LegacyRequest::Usage];
        yield 'empty new user' => [['tpl', ''], [], LegacyRequest::Usage];
        yield 'help' => [['-h'], [], LegacyRequest::Help];
        yield 'long help' => [['--help', 'x'], [], LegacyRequest::Help];
        yield 'version' => [['-V'], [], LegacyRequest::Version];
        yield 'flags only count in the first slot' => [['tpl', '--help'], ['template' => 'tpl', 'new' => '--help'], null];
    }

    /**
     * @param list<string> $argv
     * @param array<string, mixed> $input
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('translations')]
    public function testTheArgumentMapReadsTheOriginalGrammar(array $argv, array $input, ?LegacyRequest $special): void
    {
        self::assertSame([$input, $special], (new CopyUserLegacyArguments())->translate($argv));
    }

    public function testAnEmptyShimOperatorIsRejected(): void
    {
        $this->expectException(InvalidLegacyArgument::class);
        (new CopyUserLegacyArguments())->translate(['tpl', 'bob', '--as=']);
    }
}
