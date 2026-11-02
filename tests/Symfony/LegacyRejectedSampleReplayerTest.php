<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Kadupul\Graphing\Application\Port\RejectedSampleReplayResult;
use Kadupul\Graphing\Infrastructure\Legacy\LegacyRejectedSampleReplayer;
use Kadupul\Platform\Contract\LegacyWorkerRunner;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class LegacyRejectedSampleReplayerTest extends TestCase
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

    public function testItSendsTheWorkerTheScopeWithNoTimeoutAndReturnsTheCount(): void
    {
        $calls = [];
        $replayer = new LegacyRejectedSampleReplayer($this->runner(['status' => 'ok', 'samples' => 5, 'reason' => ''], $calls));

        $one = $replayer->replay(7, false);
        $all = $replayer->replay(null, true);

        self::assertTrue($one->succeeded());
        self::assertSame(5, $one->samples);
        self::assertSame(5, $all->samples);
        // No timeout: the move is one transaction and the original ran it to the end.
        self::assertSame(['legacy-rejected-samples-replay.php', 'KADUPUL_REJECTED_SAMPLES_REPLAY_RESULT', null, ['local_data_id' => 7, 'dry_run' => false]], [$calls[0]['script'], $calls[0]['marker'], $calls[0]['timeout'], $calls[0]['command']]);
        self::assertSame(['local_data_id' => null, 'dry_run' => true], $calls[1]['command']);
    }

    public function testWhatTheWorkerPrintsIsRelayedByStream(): void
    {
        $calls = [];
        $seen = [];
        $replayer = new LegacyRejectedSampleReplayer($this->runner(['status' => 'ok', 'samples' => 1, 'reason' => ''], $calls, true, [[Process::OUT, "text\n"], [Process::ERR, 'oops']]));
        $replayer->replay(null, false, static function (string $stream, string $text) use (&$seen): void {
            $seen[] = [$stream, $text];
        });

        self::assertTrue($calls[0]['progress']);
        self::assertSame([['out', "text\n"], ['err', 'oops']], $seen);
    }

    /** @return iterable<string, array{array<string, mixed>, bool, string, string}> */
    public static function endings(): iterable
    {
        yield 'an online remote collector' => [['status' => 'remote_collector', 'samples' => 0, 'reason' => ''], false, RejectedSampleReplayResult::REMOTE_COLLECTOR, ''];
        yield 'a volatile queue' => [['status' => 'unsafe_queue', 'samples' => 0, 'reason' => 'Use InnoDB.'], false, RejectedSampleReplayResult::UNSAFE_QUEUE, 'Use InnoDB.'];
        yield 'a failed move' => [['status' => 'failed', 'samples' => 0, 'reason' => ''], false, RejectedSampleReplayResult::FAILED, ''];
        yield 'ok in the marker but the worker exited badly' => [['status' => 'ok', 'samples' => 4, 'reason' => ''], false, RejectedSampleReplayResult::FAILED, ''];
        yield 'a refusal the worker exited cleanly on' => [['status' => 'remote_collector', 'samples' => 0, 'reason' => ''], true, RejectedSampleReplayResult::FAILED, ''];
        yield 'a reason on a status that has none' => [['status' => 'failed', 'samples' => 0, 'reason' => 'noise'], false, RejectedSampleReplayResult::FAILED, ''];
    }

    /** @param array<string, mixed> $answer */
    #[\PHPUnit\Framework\Attributes\DataProvider('endings')]
    public function testARefusalIsAStatusAndNeverASuccess(array $answer, bool $ok, string $status, string $reason): void
    {
        $calls = [];
        $result = (new LegacyRejectedSampleReplayer($this->runner($answer, $calls, $ok)))->replay(null, false);

        self::assertFalse($result->succeeded());
        self::assertSame([$status, 0, $reason], [$result->status, $result->samples, $result->reason]);
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function unknown(): iterable
    {
        yield 'no marker' => [[]];
        yield 'no status' => [['samples' => 1, 'reason' => '']];
        yield 'a status nobody defined' => [['status' => 'done', 'samples' => 1, 'reason' => '']];
        yield 'a status that is not text' => [['status' => 1, 'samples' => 1, 'reason' => '']];
        yield 'no count' => [['status' => 'ok', 'reason' => '']];
        yield 'a count that is text' => [['status' => 'ok', 'samples' => '3', 'reason' => '']];
        yield 'a negative count' => [['status' => 'ok', 'samples' => -1, 'reason' => '']];
        yield 'no reason' => [['status' => 'ok', 'samples' => 1]];
        yield 'a reason that is not text' => [['status' => 'ok', 'samples' => 1, 'reason' => 1]];
    }

    /** @param array<string, mixed> $answer */
    #[\PHPUnit\Framework\Attributes\DataProvider('unknown')]
    public function testAnAnswerItCannotReadIsAnUnknownOutcome(array $answer): void
    {
        $calls = [];

        $this->expectExceptionObject(new \RuntimeException('The rejected sample replay outcome is unknown.'));
        (new LegacyRejectedSampleReplayer($this->runner($answer, $calls)))->replay(null, false);
    }
}
