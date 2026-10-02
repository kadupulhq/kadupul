<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AggregateItemTransactionTest extends TestCase
{
    public static function failures(): iterable
    {
        yield ['prepare'];
        yield ['delete'];
        yield ['later-insert'];
        yield ['caller-failure'];
        yield ['mixed-owners'];
    }

    private function runFixture(string $case): array
    {
        $process = proc_open(
            [PHP_BINARY, '-d', 'auto_prepend_file=', dirname(__DIR__) . '/Fixtures/aggregate-items-transaction.php', $case],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        self::assertIsResource($process);
        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process), $error);
        self::assertSame('', $error);
        return json_decode($output, true, 512, JSON_THROW_ON_ERROR);
    }

    #[DataProvider('failures')]
    public function testRealFailurePreservesCacheAndErrorHandler(string $case): void
    {
        $result = $this->runFixture($case);
        self::assertTrue($result['reached'], 'The real PDO failure or validation boundary must be reached.');
        self::assertFalse($result['saved']);
        self::assertTrue($result['handler_preserved']);
        if ($case !== 'prepare') {
            self::assertSame($result['before'], $result['rows']);
        }
        self::assertSame($case === 'caller-failure', $result['transaction']);
        if ($case === 'caller-failure') {
            self::assertSame('preserve', $result['caller_work']);
            self::assertSame($result['before'], $result['after_caller_rollback']);
        }
    }

    public function testSuccessRespectsCallerCommitBoundary(): void
    {
        $result = $this->runFixture('caller-success');
        self::assertTrue($result['saved']);
        self::assertTrue($result['transaction']);
        self::assertSame('preserve', $result['caller_work']);
        self::assertSame([11, 12, 200], array_column($result['rows'], 'graph_templates_item_id'));
        self::assertSame($result['before'], $result['after_caller_rollback']);
        self::assertTrue($result['handler_preserved']);
    }
}
