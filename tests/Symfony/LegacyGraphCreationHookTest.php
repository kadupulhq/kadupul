<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class LegacyGraphCreationHookTest extends TestCase
{
    #[DataProvider('outcomes')]
    public function testProductionGraphCreatorPreservesLegacyAndStrictWorkerContracts(string $mode, string $outcome): void
    {
        $process = new Process([PHP_BINARY, __DIR__ . '/../Fixtures/automation-graph-hook-native.php', $mode, $outcome], dirname(__DIR__, 2));
        $process->mustRun();
        self::assertSame('', $process->getErrorOutput());
        $result = json_decode($process->getOutput(), true, 32, JSON_THROW_ON_ERROR);
        $failure = in_array($outcome, ['failure', 'exception'], true);
        $rolledBack = $mode === 'strict' && $failure;
        self::assertSame($rolledBack ? ($outcome === 'exception' ? 'Tree node save failed' : 'Graph tree automation failed') : null, $result['error']);
        self::assertSame($rolledBack ? 0 : 2, $result['graphs']);
        self::assertSame($rolledBack ? 0 : 2, $result['data']);
        self::assertSame($rolledBack ? 0 : 2, $result['links']);
        self::assertSame($outcome === 'success' ? 2 : 0, $result['tree']);
        self::assertCount($rolledBack ? 0 : 2, $result['plugins']);
        self::assertCount($mode !== 'strict' && $failure ? 2 : 0, $result['errors']);
        self::assertFalse($result['transaction']);
        if (!$rolledBack) {
            self::assertSame([1, 2], array_column($result['results'], 'local_graph_id'));
            self::assertSame([1, 2], array_column(array_column($result['plugins'], 'data'), 'id'));
            self::assertSame([7, 7], array_column(array_column($result['plugins'], 'data'), 'host_id'));
        }
    }

    public static function outcomes(): iterable
    {
        foreach (['legacy', 'legacy-false-flag', 'strict'] as $mode) {
            foreach (['success', 'failure', 'exception', 'disabled'] as $outcome) {
                yield $mode . ' ' . $outcome => [$mode, $outcome];
            }
        }
    }
}
