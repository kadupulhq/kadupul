<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Kadupul\Graphing\Application\Port\DataQueryIndexItem;
use Kadupul\Graphing\Infrastructure\Legacy\LegacyDataQueryIndexReorderer;
use Kadupul\IdentityAccess\Contract\OperatorDatabase;
use Kadupul\Platform\Contract\LegacyWorkerRunner;
use PHPUnit\Framework\TestCase;

final class LegacyDataQueryIndexReordererTest extends TestCase
{
    /** @return list<DataQueryIndexItem> */
    private static function items(int $count, string $index = '3'): array
    {
        return array_map(static fn(int $id): DataQueryIndexItem => new DataQueryIndexItem(7, 11, $index, $id, '4'), range(1, $count));
    }

    /**
     * @param \Closure(array<string, mixed>): array<string, mixed> $answer the marker JSON for a worker command
     * @param list<array<string, mixed>> $commands
     */
    private function runner(\Closure $answer, array &$commands, bool $ok = true): LegacyWorkerRunner
    {
        return new class ($answer, $commands, $ok) implements LegacyWorkerRunner {
            /** @param list<array<string, mixed>> $commands */
            public function __construct(private readonly \Closure $answer, private array &$commands, private readonly bool $ok) {}

            #[\Override]
            public function run(string $script, string $marker, array $command, ?float $timeout, ?\Closure $progress = null): array
            {
                $this->commands[] = ['script' => $script, 'marker' => $marker, 'timeout' => $timeout, ...$command];

                return ['output' => '', 'errors' => '', 'ok' => $this->ok, 'result' => ($this->answer)($command)];
            }
        };
    }

    public function testItSendsEachWorkerALimitedPayloadAndJoinsTheResults(): void
    {
        $commands = [];
        $reorderer = new LegacyDataQueryIndexReorderer($this->runner(
            static fn(array $command): array => ['status' => 'ok', 'unresolved' => false, 'index_on' => array_map(static fn(array $item): string => 'f' . $item[3], $command['items'])],
            $commands,
        ));
        // An index near the 255 character column limit keeps each item near 290 bytes.
        $result = $reorderer->reorder(OperatorDatabase::Main, self::items(150, str_repeat('x', 250)));

        self::assertGreaterThan(1, count($commands));
        foreach ($commands as $command) {
            self::assertLessThanOrEqual(19000, strlen(json_encode($command['items'], JSON_THROW_ON_ERROR)));
            self::assertSame(['legacy-data-query-reorder.php', LegacyDataQueryIndexReorderer::MARKER, null, 'main'], [$command['script'], $command['marker'], $command['timeout'], $command['database']]);
        }
        self::assertSame(array_map(static fn(int $id): string => 'f' . $id, range(1, 150)), $result->indexOn);
        self::assertFalse($result->unresolved);
    }

    public function testItStopsAtTheFirstUnresolvedItemWithoutStartingAnotherWorker(): void
    {
        $commands = [];
        $reorderer = new LegacyDataQueryIndexReorderer($this->runner(
            static fn(array $command): array => ['status' => 'ok', 'unresolved' => true, 'index_on' => ['a']],
            $commands,
        ));
        $result = $reorderer->reorder(OperatorDatabase::Local, self::items(150, str_repeat('x', 250)));

        self::assertCount(1, $commands);
        self::assertSame([['a'], true], [$result->indexOn, $result->unresolved]);
    }

    /** @return iterable<string, array{array<string, mixed>, bool}> */
    public static function unknown(): iterable
    {
        yield 'the worker failed' => [['status' => 'failed', 'unresolved' => false, 'index_on' => []], false];
        yield 'no list' => [['status' => 'ok', 'unresolved' => false], true];
        yield 'a short run that is not marked unresolved' => [['status' => 'ok', 'unresolved' => false, 'index_on' => ['a']], true];
        yield 'more fields than items' => [['status' => 'ok', 'unresolved' => false, 'index_on' => ['a', 'b', 'c']], true];
        yield 'a field that is not text' => [['status' => 'ok', 'unresolved' => false, 'index_on' => [0, 1]], true];
        yield 'an unresolved flag that is not boolean' => [['status' => 'ok', 'unresolved' => 'yes', 'index_on' => ['a', 'b']], true];
    }

    /** @param array<string, mixed> $answer */
    #[\PHPUnit\Framework\Attributes\DataProvider('unknown')]
    public function testAnOutcomeItCannotTrustIsAnError(array $answer, bool $ok): void
    {
        $commands = [];
        $reorderer = new LegacyDataQueryIndexReorderer($this->runner(static fn(): array => $answer, $commands, $ok));

        $this->expectExceptionObject(new \RuntimeException('Data query reorder outcome is unknown.'));
        $reorderer->reorder(OperatorDatabase::Local, self::items(2));
    }
}
