<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Doctrine\DBAL\Connection;
use Kadupul\Graphing\Application\Command\GraphCommandAccess;
use Kadupul\Graphing\Application\Command\RebuildPollerCache;
use Kadupul\Graphing\Application\Port\PollerCacheOutcome;
use Kadupul\Graphing\Application\Port\PollerCacheRequest;
use Kadupul\Graphing\Infrastructure\Symfony\RebuildPollerCacheCommand;
use Kadupul\Graphing\Infrastructure\Symfony\RebuildPollerCacheLegacyArguments;
use Kadupul\IdentityAccess\Contract\AuditTrail;
use Kadupul\IdentityAccess\Contract\CollectorTopology;
use Kadupul\IdentityAccess\Infrastructure\Cli\CliConsoleAccess;
use Kadupul\Platform\Infrastructure\Legacy\InstallationVersion;
use Kadupul\Platform\Infrastructure\Symfony\Console\CliPresentation;
use Kadupul\Platform\Infrastructure\Symfony\Console\InvalidLegacyArgument;
use Kadupul\Platform\Infrastructure\Symfony\Console\LegacyRequest;
use Kadupul\Platform\Infrastructure\Symfony\Console\ResultRenderer;
use Kadupul\Tests\Fixtures\ConsoleOperatorDatabase;
use Kadupul\Tests\Fixtures\InMemoryPollerCache;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;

/** kadupul:poller-cache:rebuild in shim, JSON and bin/console modes, and its legacy argument map. */
final class RebuildPollerCacheCommandTest extends TestCase
{
    use ConsoleOperatorDatabase;

    private string $root;
    private Connection $db;
    private CliPresentation $presentation;

    protected function setUp(): void
    {
        $this->root = $this->installationRoot('kadupul-rebuild-poller-cache-command-');
        $this->db = $this->operatorDatabase(8, 15);
        $this->presentation = new CliPresentation();
        $this->presentation->forLegacy(LegacyRequest::Run);
    }

    private function command(InMemoryPollerCache $cache): RebuildPollerCacheCommand
    {
        $access = new GraphCommandAccess(new CliConsoleAccess($this->db, $this->db), $this->createStub(CollectorTopology::class), $this->createStub(AuditTrail::class));
        $clock = new MockClock('2031-06-01 00:00:00');
        $version = new InstallationVersion($this->root, $this->db, new Filesystem(), $clock);

        return new RebuildPollerCacheCommand(new RebuildPollerCache($access, $cache, $cache), $version, $clock, $this->presentation, new ResultRenderer());
    }

    private function tester(InMemoryPollerCache $cache): CommandTester
    {
        return new CommandTester(new Command(null, $this->command($cache)));
    }

    public function testARunStreamsWhatTheScriptPrintedAndPassesTheValuesOnAsTyped(): void
    {
        $cache = new InMemoryPollerCache(printed: [['out', "WARNING: Do not interrupt\n"], ['out', '..']]);
        $tester = $this->tester($cache);

        self::assertSame(0, $tester->execute(['--host-id' => ' 007 ', '--host-template-id' => ' 1e0', '--data-template-id' => '2.0', '--threads' => ' 3', '--type' => 'child', '--child' => 'x', '--force' => true, '--debug' => true]));
        self::assertSame("WARNING: Do not interrupt\n..", $tester->getDisplay());
        self::assertEquals([new PollerCacheRequest('007', '1e0', '2.0', 3, 'child', 'x', true, true)], $cache->requests);
        self::assertSame(0, $tester->execute([]));
        self::assertEquals(new PollerCacheRequest(), $cache->requests[1], 'no arguments rebuilds everything as the master');
        self::assertSame(0, $tester->execute(['--type' => '']));
        self::assertSame('', $cache->requests[2]->type, 'an empty --type is a type of its own');
    }

    public function testEachRefusalPrintsTheOriginalLineWhereTheScriptPrintedItAndExitsOne(): void
    {
        $cache = new InMemoryPollerCache();
        $tester = $this->tester($cache);
        foreach (['0', '-1', '1.5', 'abc', '', ' '] as $host) {
            self::assertSame(1, $tester->execute(['--host-id' => $host], ['capture_stderr_separately' => true]), $host);
            self::assertSame('', $tester->getDisplay());
            self::assertSame("ERROR: --host-id must be a positive integer.\n", $tester->getErrorOutput());
        }
        foreach ([['--host-template-id' => 'abc'], ['--host-template-id' => '']] as $arguments) {
            self::assertSame(1, $tester->execute($arguments));
            self::assertSame("ERROR: You must supply a valid Device Template Id to run this script!\n", $tester->getDisplay());
        }
        self::assertSame(1, $tester->execute(['--data-template-id' => '0x1']));
        self::assertSame("ERROR: You must supply a valid Data Template Id to run this script!\n", $tester->getDisplay());
        foreach (['0', '-1', '1.5', 'x', '', '05'] as $threads) {
            self::assertSame(1, $tester->execute(['--threads' => $threads]), $threads);
            self::assertSame("ERROR: You must supply a valid Number of Treads or skip this parameter for default value (4)\n", $tester->getDisplay());
        }
        self::assertSame([], $cache->requests);
    }

    public function testAFailedRunNamesTheFailureAndAnInterruptedOneEndsQuietly(): void
    {
        $tester = $this->tester(new InMemoryPollerCache(outcome: null, printed: [['out', "WARNING\n"]]));
        self::assertSame(1, $tester->execute([]));
        self::assertSame("WARNING\nERROR: Rebuilding the poller cache failed\n", $tester->getDisplay());

        $tester = $this->tester(new InMemoryPollerCache(new PollerCacheOutcome(PollerCacheOutcome::INTERRUPTED), [['out', "WARNING\n"]]));
        self::assertSame(1, $tester->execute([]));
        self::assertSame("WARNING\n", $tester->getDisplay());

        foreach ([PollerCacheOutcome::NO_HOSTS, PollerCacheOutcome::ALREADY_RUNNING, PollerCacheOutcome::SKIPPED] as $status) {
            self::assertSame(0, $this->tester(new InMemoryPollerCache(new PollerCacheOutcome($status)))->execute([]), $status);
        }
    }

    public function testTheOperatorNeedsTheUtilitiesRealmBeforeTheSettingIsQuoted(): void
    {
        $this->db->executeStatement('DELETE FROM user_auth_realm WHERE realm_id = 15');
        $cache = new InMemoryPollerCache();
        $tester = $this->tester($cache);

        foreach ([[], ['--threads' => 'x']] as $arguments) {
            self::assertSame(1, $tester->execute($arguments));
            self::assertSame("ERROR: Unknown or unauthorized operator\n", $tester->getDisplay());
        }
        self::assertSame([[], 0], [$cache->requests, $cache->settingReads]);
    }

    public function testVersionAndHelpAreTheOriginalsWordForWord(): void
    {
        $this->presentation->forLegacy(LegacyRequest::Version);
        $tester = $this->tester(new InMemoryPollerCache());
        self::assertSame(0, $tester->execute([]));
        self::assertSame("Kadupul Rebuild poller cache Tool, Version 1.3.0 Copyright (C) 2004-2031 The Cacti Group\n", $tester->getDisplay());

        $this->presentation->forLegacy(LegacyRequest::Help);
        $tester = $this->tester(new InMemoryPollerCache());
        self::assertSame(0, $tester->execute([]));
        self::assertStringStartsWith("Kadupul Rebuild poller cache Tool, Version 1.3.0 Copyright (C) 2004-2031 The Cacti Group\n\nusage: rebuild_poller_cache.php [--host-id=N]", $tester->getDisplay());
        self::assertStringEndsWith(" --child     - The thread id of the child process\n\n", $tester->getDisplay());
    }

    public function testJsonReportsTheOutcomeWithoutTheProgressAndBinConsoleRejectsBadInputAsInvalid(): void
    {
        $tester = $this->tester(new InMemoryPollerCache(printed: [['out', "WARNING\n"]]));
        self::assertSame(0, $tester->execute(['--json' => true, '--type' => 'child']));
        self::assertSame(['status' => 'rebuilt', 'database' => 'local', 'type' => 'child', 'hosts' => 2], json_decode($tester->getDisplay(), true));

        $this->presentation = new CliPresentation();
        $cache = new InMemoryPollerCache();
        foreach ([['--host-id' => '0'], ['--host-template-id' => 'x'], ['--data-template-id' => 'x'], ['--threads' => 'x']] as $arguments) {
            self::assertSame(Command::INVALID, $this->tester($cache)->execute($arguments), json_encode($arguments, JSON_THROW_ON_ERROR));
        }
        self::assertSame(0, $cache->settingReads, 'bin/console does not quote the setting');
        $tester = $this->tester(new InMemoryPollerCache(printed: [['out', "WARNING\n"]]));
        self::assertSame(0, $tester->execute([]));
        self::assertStringContainsString('The poller cache was rebuilt for 2 device(s).', $tester->getDisplay());
    }

    public function testASignalGoesToTheRunningWorkerOrEndsTheCommand(): void
    {
        $running = new InMemoryPollerCache(running: true);
        $idle = new InMemoryPollerCache();

        self::assertSame(\defined('SIGINT') ? [\SIGINT, \SIGTERM] : [], $this->command($running)->getSubscribedSignals());
        self::assertFalse($this->command($running)->handleSignal(15));
        self::assertSame(1, $this->command($idle)->handleSignal(15));
        self::assertSame([15], $running->signals);
    }

    public function testTheOldArgumentsAreReadAsTheScriptDidNotAsTheBaseMapDoes(): void
    {
        $arguments = new RebuildPollerCacheLegacyArguments();

        self::assertSame([['--host-id' => '7', '--threads' => '2', '--debug' => true, '--force' => true], null], $arguments->translate(['--host-id=7', '--threads=2', '-d=1', '--force=no']));
        self::assertSame([['--type' => '', '--child' => ''], null], $arguments->translate(['--type', '--child']), 'a bare flag has an empty value');
        self::assertSame([['--host-id' => ''], null], $arguments->translate(['--host-id', '--bogus']), 'a bare id is refused, and the run stops at it');
        self::assertSame([['--host-id' => 'abc'], null], $arguments->translate(['--host-id=abc', '--threads=x', '--bogus']), 'the first bad value ends the run');
        self::assertSame([['--threads' => 'x'], null], $arguments->translate(['--threads=x', '--threads=3']));
        self::assertSame([['--host-id' => '9'], null], $arguments->translate(['--host-id=3', '--host-id=9']), 'the last value wins');
        self::assertSame([['--host-template-id' => ' 1e0 '], null], $arguments->translate(['--host-template-id= 1e0 ']), 'trimmed to test it, and sent as typed');
        self::assertSame([[], null], $arguments->translate([]), 'no arguments is not a help request');
        self::assertSame(LegacyRequest::Help, $arguments->translate(['--help', '--host-id=x'])[1]);
        self::assertSame(LegacyRequest::Version, $arguments->translate(['-V', '--bogus'])[1]);
        self::assertSame(LegacyRequest::Help, $arguments->translate(['-H=1'])[1]);
        foreach (['--quiet', '--list-hosts', '=1', '-D', '--as=admin', '--local'] as $unknown) {
            try {
                $arguments->translate([$unknown]);
                self::fail($unknown . ' was accepted.');
            } catch (InvalidLegacyArgument $error) {
                self::assertSame(['ERROR: Invalid Parameter ' . $unknown, '', ''], array_slice($arguments->invalidOutput($error->argument), 0, 3));
            }
        }
    }

    public function testABadThreadCountAfterAGoodOneQuotesTheGoodOneBeforeAnythingRuns(): void
    {
        $arguments = new RebuildPollerCacheLegacyArguments();
        try {
            $arguments->translate(['--threads=3', '--host-id=5', '--threads=0']);
            self::fail('The bad thread count was accepted.');
        } catch (InvalidLegacyArgument $error) {
            self::assertSame('--threads=0', $error->argument);
            self::assertSame(['ERROR: You must supply a valid Number of Treads or skip this parameter for default value (3)'], $arguments->invalidOutput($error->argument));
        }
    }
}
