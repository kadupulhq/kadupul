<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Kadupul\Graphing\Domain\ReindexMethod;
use Kadupul\Graphing\Infrastructure\Legacy\LegacyDataQueryAssociator;
use Kadupul\IdentityAccess\Contract\OperatorDatabase;
use Kadupul\Platform\Contract\LegacyWorkerRunner;
use PHPUnit\Framework\TestCase;

final class LegacyDataQueryAssociatorTest extends TestCase
{
    /**
     * @param array<string, mixed> $answer
     * @param list<array<string, mixed>> $commands
     */
    private function runner(array $answer, array &$commands, bool $ok = true): LegacyWorkerRunner
    {
        return new class ($answer, $commands, $ok) implements LegacyWorkerRunner {
            /**
             * @param array<string, mixed> $answer
             * @param list<array<string, mixed>> $commands
             */
            public function __construct(private readonly array $answer, private array &$commands, private readonly bool $ok) {}

            #[\Override]
            public function run(string $script, string $marker, array $command, ?float $timeout, ?\Closure $progress = null): array
            {
                $this->commands[] = ['script' => $script, 'marker' => $marker, 'timeout' => $timeout, ...$command];

                return ['output' => '', 'errors' => '', 'ok' => $this->ok, 'result' => $this->answer];
            }
        };
    }

    public function testItSendsTheWorkerTheIdsAndTheMethodAsTyped(): void
    {
        $commands = [];
        $associator = new LegacyDataQueryAssociator($this->runner(['status' => 'ok'], $commands));
        $associator->associate(OperatorDatabase::Main, 7, 3, ReindexMethod::parse('uptime') ?? throw new \LogicException('parses'));
        $associator->associate(OperatorDatabase::Local, 8, 4, ReindexMethod::parse('1.5') ?? throw new \LogicException('parses'));

        // No timeout: the original ran the query to its end.
        self::assertSame(['legacy-data-query-associate.php', 'KADUPUL_DATA_QUERY_ASSOCIATE_RESULT', null, 'main', 7, 3, 1], [$commands[0]['script'], $commands[0]['marker'], $commands[0]['timeout'], $commands[0]['database'], $commands[0]['host_id'], $commands[0]['data_query_id'], $commands[0]['reindex_method']]);
        self::assertSame(['local', 8, 4, '1.5'], [$commands[1]['database'], $commands[1]['host_id'], $commands[1]['data_query_id'], $commands[1]['reindex_method']]);
    }

    /** @return iterable<string, array{array<string, mixed>, bool}> */
    public static function failures(): iterable
    {
        yield 'the worker failed' => [['status' => 'failed'], false];
        yield 'the worker reported a failed query run' => [['status' => 'failed'], true];
        yield 'no status' => [[], true];
        yield 'a status that is not ok' => [['status' => true], true];
    }

    /** @param array<string, mixed> $answer */
    #[\PHPUnit\Framework\Attributes\DataProvider('failures')]
    public function testAnOutcomeThatIsNotOkIsAnError(array $answer, bool $ok): void
    {
        $commands = [];
        $associator = new LegacyDataQueryAssociator($this->runner($answer, $commands, $ok));

        $this->expectExceptionObject(new \RuntimeException('The data query could not be associated.'));
        $associator->associate(OperatorDatabase::Local, 7, 3, ReindexMethod::parse('1') ?? throw new \LogicException('parses'));
    }
}
