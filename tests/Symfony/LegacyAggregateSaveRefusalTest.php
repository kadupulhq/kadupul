<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class LegacyAggregateSaveRefusalTest extends TestCase
{
    public static function pages(): iterable
    {
        yield ['aggregate_templates.php', 'aggregate_graph_templates_item'];
        yield ['aggregate_graphs.php', 'aggregate_graphs_graph_item'];
        yield ['graphs.php', 'aggregate_graphs_graph_item'];
    }

    #[DataProvider('pages')]
    public function testFailedReplacementNeverPropagatesOrReportsSuccess(string $page, string $table): void
    {
        $fixture = dirname(__DIR__) . '/Fixtures/aggregate-caller-save-refusal.php';
        $process = proc_open(
            [PHP_BINARY, '-d', 'auto_prepend_file=', $fixture, $page],
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
        $trace = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
        self::assertCount(1, $trace['items']);
        self::assertSame($table, $trace['items'][0]['table']);
        self::assertNotEmpty($trace['items'][0]['items']);
        self::assertSame(7, $trace['items'][0]['items'][0]['graph_templates_item_id']);
        self::assertSame(0, $trace['propagation']);
        self::assertSame(0, $trace['generation']);
        self::assertCount(1, $trace['messages']);
        if ($page === 'graphs.php') {
            self::assertSame('aggregate_regeneration_failed', $trace['messages'][0][0]);
            self::assertSame('Aggregate graph creation could not be confirmed. Review the graph settings before retrying.', $trace['messages'][0][1]);
            self::assertSame([['selected_items' => [11], 'before' => 0, 'after' => 0, 'result' => false]], $trace['creation']);
            self::assertSame([[0, 1, 'Aggregate', 0]], $trace['graph_creation_arguments']);
            self::assertSame([false], $trace['mutation_results']);
        } else {
            self::assertSame('aggregate_items_save_failed', $trace['messages'][0][0]);
            self::assertStringContainsString('Other graph settings may already have been saved', $trace['messages'][0][1]);
            self::assertSame([], $trace['creation']);
        }
    }
}
