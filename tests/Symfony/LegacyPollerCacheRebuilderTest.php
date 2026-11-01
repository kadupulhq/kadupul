<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Kadupul\Graphing\Application\Port\PollerCacheOutcome;
use Kadupul\Graphing\Application\Port\PollerCacheRequest;
use Kadupul\Graphing\Infrastructure\Legacy\LegacyPollerCacheRebuilder;
use Kadupul\IdentityAccess\Contract\OperatorDatabase;
use Kadupul\Platform\Contract\LegacyWorkerRunner;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Exception\ProcessSignaledException;
use Symfony\Component\Process\Process;

/** The worker handoff: request JSON, streamed output, result line checks and signal forwarding. */
final class LegacyPollerCacheRebuilderTest extends TestCase
{
    /** @var list<array<string, mixed>> */
    private array $commands = [];

    /**
     * A worker that prints $lines through the progress closure, runs $during,
     * then answers with $result.
     *
     * @param list<string> $lines stdout lines as LegacyWorkerProcess hands them over, the marker line excluded
     * @param array<mixed> $result
     */
    private function runner(array $lines, array $result, bool $ok = true, ?\Closure $during = null): LegacyWorkerRunner
    {
        $commands = &$this->commands;

        return new class ($lines, $result, $ok, $during, $commands) implements LegacyWorkerRunner {
            /**
             * @param list<string> $lines
             * @param array<mixed> $result
             * @param list<array<string, mixed>> $commands
             */
            public function __construct(private readonly array $lines, private readonly array $result, private readonly bool $ok, private readonly ?\Closure $during, private array &$commands) {}

            #[\Override]
            public function run(string $script, string $marker, array $command, ?float $timeout, ?\Closure $progress = null): array
            {
                $this->commands[] = ['script' => $script, 'marker' => $marker, 'timeout' => $timeout, ...$command];
                foreach ($this->lines as $line) {
                    $progress?->__invoke('out', $line);
                }
                $progress?->__invoke('err', 'warning on stderr');
                if ($this->during !== null) {
                    ($this->during)();
                }

                return ['output' => implode('', $this->lines), 'errors' => '', 'ok' => $this->ok, 'result' => $this->result];
            }
        };
    }

    /** @var list<array{string, string}> what the progress closure received */
    private array $seen = [];

    /** @return \Closure(string, string): void */
    private function recorder(): \Closure
    {
        return function (string $type, string $text): void {
            $this->seen[] = [$type, $text];
        };
    }

    public function testItSendsTheRequestAndRelaysTheOutputWithoutTheProcessLine(): void
    {
        $progress = $this->recorder();
        $rebuilder = new LegacyPollerCacheRebuilder($this->runner([LegacyPollerCacheRebuilder::PID . "4242\n", "WARNING\n", "There are 2 hosts\n"], ['status' => 'ok', 'outcome' => 'rebuilt', 'hosts' => 2, 'newline' => true]));
        $request = new PollerCacheRequest('7', '3', '1e0', 2, 'child', '1', true, true);

        $outcome = $rebuilder->rebuild(OperatorDatabase::Main, $request, $progress);

        self::assertSame([PollerCacheOutcome::REBUILT, 2], [$outcome->status, $outcome->hosts]);
        // Each stdout line waits for the next one, so stderr can overtake it.
        self::assertSame([['out', "WARNING\n"], ['err', 'warning on stderr'], ['out', "There are 2 hosts\n"]], $this->seen);
        // No timeout: the original warned never to interrupt a rebuild.
        self::assertSame(['script' => 'legacy-poller-cache-rebuild.php', 'marker' => 'KADUPUL_POLLER_CACHE_RESULT', 'timeout' => null, 'database' => 'main', 'host_id' => '7',
            'host_template_id' => '3', 'data_template_id' => '1e0', 'threads' => 2, 'type' => 'child', 'child' => '1', 'force' => true, 'debug' => true,
            'ini' => ['display_errors' => (string) ini_get('display_errors'), 'log_errors' => (string) ini_get('log_errors'), 'error_reporting' => (string) ini_get('error_reporting')]], $this->commands[0]);
    }

    public function testTheNewlineTheWorkerAddedBeforeItsResultIsTakenOff(): void
    {
        $progress = $this->recorder();
        $rebuilder = new LegacyPollerCacheRebuilder($this->runner([LegacyPollerCacheRebuilder::PID . "4242\n", "WARNING\n", "..\n"], ['status' => 'ok', 'outcome' => 'rebuilt', 'hosts' => 2, 'newline' => false]));

        $rebuilder->rebuild(OperatorDatabase::Local, new PollerCacheRequest(), $progress);

        self::assertSame([['out', "WARNING\n"], ['err', 'warning on stderr'], ['out', '..']], $this->seen);
    }

    public function testAnInterruptedRunIsAnOutcomeNotAnError(): void
    {
        $rebuilder = new LegacyPollerCacheRebuilder($this->runner([], ['status' => 'interrupted', 'newline' => true], false));

        self::assertSame(PollerCacheOutcome::INTERRUPTED, $rebuilder->rebuild(OperatorDatabase::Local, new PollerCacheRequest())->status);
    }

    /** @return iterable<string, array{array<mixed>, bool}> */
    public static function failures(): iterable
    {
        yield 'the worker failed' => [['status' => 'failed'], false];
        yield 'no result line' => [[], false];
        yield 'an ok exit with a failed status' => [['status' => 'failed', 'outcome' => 'rebuilt', 'hosts' => 1], true];
        yield 'an unknown outcome' => [['status' => 'ok', 'outcome' => 'done', 'hosts' => 1], true];
        yield 'an interruption claimed as an outcome' => [['status' => 'ok', 'outcome' => 'interrupted', 'hosts' => 0], true];
        yield 'no host count' => [['status' => 'ok', 'outcome' => 'rebuilt'], true];
        yield 'a negative host count' => [['status' => 'ok', 'outcome' => 'rebuilt', 'hosts' => -1], true];
        yield 'a host count that is text' => [['status' => 'ok', 'outcome' => 'rebuilt', 'hosts' => '1'], true];
    }

    /** @param array<mixed> $result */
    #[\PHPUnit\Framework\Attributes\DataProvider('failures')]
    public function testAnOutcomeThatIsNotAKnownOneIsAnError(array $result, bool $ok): void
    {
        $rebuilder = new LegacyPollerCacheRebuilder($this->runner([], $result, $ok));

        $this->expectExceptionObject(new \RuntimeException('The poller cache rebuild failed.'));
        $rebuilder->rebuild(OperatorDatabase::Local, new PollerCacheRequest());
    }

    public function testNoRunMeansTheSignalIsNotTakenAndAProcessLineAfterOutputIsOutput(): void
    {
        $progress = $this->recorder();
        $rebuilder = new LegacyPollerCacheRebuilder($this->runner(["WARNING\n", LegacyPollerCacheRebuilder::PID . "1\n"], ['status' => 'ok', 'outcome' => 'skipped', 'hosts' => 0, 'newline' => true]));

        self::assertFalse($rebuilder->interrupt(\defined('SIGTERM') ? \SIGTERM : 15));
        $rebuilder->rebuild(OperatorDatabase::Local, new PollerCacheRequest(), $progress);
        self::assertSame(['out', LegacyPollerCacheRebuilder::PID . "1\n"], $this->seen[2]);
        self::assertFalse($rebuilder->interrupt(15), 'the run is over');
    }

    public function testASignalDuringTheRunReachesTheWorkerProcessEvenBeforeItAnnouncedItself(): void
    {
        if (!function_exists('posix_kill') || !\defined('SIGTERM')) {
            self::markTestSkipped('posix and pcntl are needed to pass a signal on.');
        }
        foreach ([true, false] as $early) {
            $sleeper = new Process([PHP_BINARY, '-r', 'sleep(30);']);
            $sleeper->start();
            $rebuilder = null;
            $taken = null;
            $lines = [LegacyPollerCacheRebuilder::PID . $sleeper->getPid() . "\n"];
            $during = static function () use (&$rebuilder, &$taken): void {
                $taken = $rebuilder->interrupt(\SIGTERM);
            };
            $runner = $this->runner($lines, ['status' => 'interrupted', 'newline' => true], false, $during);
            if ($early) {
                // The signal arrives before the process line: it is held and sent once the line comes.
                $runner = new class ($runner, $lines, $rebuilder, $taken) implements LegacyWorkerRunner {
                    /** @param list<string> $lines */
                    public function __construct(private readonly LegacyWorkerRunner $inner, private readonly array $lines, private mixed &$rebuilder, private mixed &$taken) {}

                    #[\Override]
                    public function run(string $script, string $marker, array $command, ?float $timeout, ?\Closure $progress = null): array
                    {
                        $this->taken = $this->rebuilder->interrupt(\SIGTERM);
                        foreach ($this->lines as $line) {
                            $progress?->__invoke('out', $line);
                        }

                        return ['output' => '', 'errors' => '', 'ok' => false, 'result' => ['status' => 'interrupted']];
                    }
                };
            }
            $rebuilder = new LegacyPollerCacheRebuilder($runner);

            self::assertSame(PollerCacheOutcome::INTERRUPTED, $rebuilder->rebuild(OperatorDatabase::Local, new PollerCacheRequest())->status);
            self::assertTrue($taken);
            try {
                $sleeper->wait();
                self::fail('The worker process was not signalled.');
            } catch (ProcessSignaledException $signalled) {
                self::assertSame(\SIGTERM, $signalled->getSignal(), $early ? 'held signal' : 'forwarded signal');
            }
        }
    }
}
