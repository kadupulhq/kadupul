<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Kadupul\Graphing\Infrastructure\Legacy\LegacyDataSourceCreator;
use Kadupul\IdentityAccess\Contract\OperatorDatabase;
use Kadupul\Platform\Contract\LegacyWorkerRunner;
use PHPUnit\Framework\TestCase;

final class LegacyDataSourceCreatorTest extends TestCase
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

    public function testItSendsTheWorkerTheIdsAndReturnsTheNewId(): void
    {
        $commands = [];
        $creator = new LegacyDataSourceCreator($this->runner(['status' => 'ok', 'id' => 41], $commands));

        self::assertSame(41, $creator->create(OperatorDatabase::Main, 7, 3, true));
        self::assertSame(41, $creator->create(OperatorDatabase::Local, 0, 0, false));
        // No timeout: stopping the template application half way would leave a half-built data source.
        self::assertSame(['legacy-data-source-add.php', 'KADUPUL_DATA_SOURCE_ADD_RESULT', null, 'main', 7, 3, true], [$commands[0]['script'], $commands[0]['marker'], $commands[0]['timeout'], $commands[0]['database'], $commands[0]['host_id'], $commands[0]['data_template_id'], $commands[0]['push_out']]);
        self::assertSame(['local', 0, 0, false], [$commands[1]['database'], $commands[1]['host_id'], $commands[1]['data_template_id'], $commands[1]['push_out']]);
    }

    /** @return iterable<string, array{array<string, mixed>, bool}> */
    public static function failures(): iterable
    {
        yield 'the worker failed' => [['status' => 'failed', 'id' => 0], false];
        yield 'the worker reported a failure' => [['status' => 'failed', 'id' => 41], true];
        yield 'no status' => [['id' => 41], true];
        yield 'no id' => [['status' => 'ok'], true];
        yield 'an id of zero' => [['status' => 'ok', 'id' => 0], true];
        yield 'an id that is text' => [['status' => 'ok', 'id' => '41'], true];
    }

    /** @param array<string, mixed> $answer */
    #[\PHPUnit\Framework\Attributes\DataProvider('failures')]
    public function testAnOutcomeThatIsNotOkWithAnIdIsAnError(array $answer, bool $ok): void
    {
        $commands = [];
        $creator = new LegacyDataSourceCreator($this->runner($answer, $commands, $ok));

        $this->expectExceptionObject(new \RuntimeException('The data source could not be added.'));
        $creator->create(OperatorDatabase::Local, 7, 3, true);
    }
}
