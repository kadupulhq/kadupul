<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Kadupul\Graphing\Application\Port\PollerOutputDrainResult;
use Kadupul\Graphing\Infrastructure\Legacy\LegacyPollerOutputDrain;
use Kadupul\Platform\Contract\LegacyWorkerRunner;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class LegacyPollerOutputDrainTest extends TestCase
{
    /**
     * @param array<string, mixed> $answer
     * @param list<array<string, mixed>> $calls
     * @param list<array{string, string}> $printed what the worker printed, by Process stream
     */
    private function runner(array $answer, array &$calls, bool $ok = true, array $printed = []): LegacyWorkerRunner
    {
        return new class ($answer, $calls, $ok, $printed) implements LegacyWorkerRunner {
            /**
             * @param array<string, mixed> $answer
             * @param list<array<string, mixed>> $calls
             * @param list<array{string, string}> $printed
             */
            public function __construct(private readonly array $answer, private array &$calls, private readonly bool $ok, private readonly array $printed) {}

            #[\Override]
            public function run(string $script, string $marker, array $command, ?float $timeout, ?\Closure $progress = null): array
            {
                $this->calls[] = ['script' => $script, 'marker' => $marker, 'timeout' => $timeout, 'command' => $command, 'progress' => $progress !== null];
                foreach ($this->printed as [$type, $text]) {
                    if ($progress !== null) {
                        $progress($type, $text);
                    }
                }

                return ['output' => '', 'errors' => '', 'ok' => $this->ok, 'result' => $this->answer];
            }
        };
    }

    public function testItAsksTheWorkerForOnePassWithNoTimeoutAndReturnsTheUpdates(): void
    {
        $calls = [];
        $result = (new LegacyPollerOutputDrain($this->runner(['status' => 'ok', 'updates' => 6], $calls)))->drain();

        self::assertTrue($result->succeeded());
        self::assertSame(6, $result->updates);
        // No timeout: the original ran until the queue was empty.
        self::assertSame([['script' => 'legacy-poller-output-empty.php', 'marker' => 'KADUPUL_POLLER_OUTPUT_EMPTY_RESULT', 'timeout' => null, 'command' => [], 'progress' => false]], $calls);
    }

    public function testWhatTheWorkerPrintsIsRelayedByStream(): void
    {
        $calls = [];
        $seen = [];
        $drain = new LegacyPollerOutputDrain($this->runner(['status' => 'ok', 'updates' => 1], $calls, true, [[Process::OUT, "OK u:0.01\n"], [Process::ERR, 'oops']]));
        $drain->drain(static function (string $stream, string $text) use (&$seen): void {
            $seen[] = [$stream, $text];
        });

        self::assertTrue($calls[0]['progress']);
        self::assertSame([['out', "OK u:0.01\n"], ['err', 'oops']], $seen);
    }

    /** @return iterable<string, array{array<string, mixed>, bool, string}> */
    public static function endings(): iterable
    {
        yield 'rrdtool could not start' => [['status' => 'rrd_unavailable', 'updates' => 0], false, PollerOutputDrainResult::RRD_UNAVAILABLE];
        yield 'the queue could not be counted' => [['status' => 'count_unavailable', 'updates' => 0], false, PollerOutputDrainResult::COUNT_UNAVAILABLE];
        yield 'no progress' => [['status' => 'no_progress', 'updates' => 0], false, PollerOutputDrainResult::NO_PROGRESS];
        yield 'the worker said failed' => [['status' => 'failed', 'updates' => 0], false, PollerOutputDrainResult::FAILED];
        yield 'ok in the marker but the worker exited badly' => [['status' => 'ok', 'updates' => 4], false, PollerOutputDrainResult::FAILED];
        yield 'a refusal the worker exited cleanly on' => [['status' => 'no_progress', 'updates' => 0], true, PollerOutputDrainResult::FAILED];
    }

    /** @param array<string, mixed> $answer */
    #[\PHPUnit\Framework\Attributes\DataProvider('endings')]
    public function testARefusalIsAStatusAndNeverASuccess(array $answer, bool $ok, string $status): void
    {
        $calls = [];
        $result = (new LegacyPollerOutputDrain($this->runner($answer, $calls, $ok)))->drain();

        self::assertFalse($result->succeeded());
        self::assertSame([$status, 0], [$result->status, $result->updates]);
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function unknown(): iterable
    {
        yield 'no marker' => [[]];
        yield 'no status' => [['updates' => 1]];
        yield 'a status nobody defined' => [['status' => 'done', 'updates' => 1]];
        yield 'a status that is not text' => [['status' => 1, 'updates' => 1]];
        yield 'no updates' => [['status' => 'ok']];
        yield 'updates that are text' => [['status' => 'ok', 'updates' => '3']];
        yield 'negative updates' => [['status' => 'ok', 'updates' => -1]];
    }

    /** @param array<string, mixed> $answer */
    #[\PHPUnit\Framework\Attributes\DataProvider('unknown')]
    public function testAnAnswerItCannotReadIsAnUnknownOutcome(array $answer): void
    {
        $calls = [];

        $this->expectExceptionObject(new \RuntimeException('The poller output outcome is unknown.'));
        (new LegacyPollerOutputDrain($this->runner($answer, $calls)))->drain();
    }
}
