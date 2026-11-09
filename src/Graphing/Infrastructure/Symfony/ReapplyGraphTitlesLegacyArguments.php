<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Graphing\Infrastructure\Symfony;

use Kadupul\Graphing\Application\Port\GraphTitle;

/** The arguments of poller_graphs_reapply_names.php; see HostSelectionLegacyArguments. */
final class ReapplyGraphTitlesLegacyArguments extends HostSelectionLegacyArguments
{
    #[\Override]
    protected function selectionFlags(): array
    {
        return [
            '-s' => ['filter', true],
            '--filter' => ['filter', true],
        ];
    }

    #[\Override]
    protected function usage(): string
    {
        return 'usage: poller_graphs_reapply_names.php --host-id=[id|all][N1,N2,...] [--filter=[string] [--debug]';
    }

    #[\Override]
    protected function description(): string
    {
        return 'A utility to reapply Kadupul Graph naming rules to existing Graphs in bulk.';
    }

    #[\Override]
    protected function required(): array
    {
        return ["    --host-id=id|all|N1,N2,... - The devices id, 'all' or a comma delimited list of id's"];
    }

    #[\Override]
    protected function optional(): array
    {
        return [
            '    --filter=string            - A Graph Template name or Graph Title to search for',
            '    --debug                    - Display verbose output during execution',
        ];
    }

    /**
     * What the original printed for a run: a warning, then a dot for each graph,
     * or with --debug a line before and after each.
     *
     * @param list<GraphTitle> $graphs
     * @return array{0: list<string>, 1: bool} the lines, and whether the last ends in a newline
     */
    public function run(array $graphs, bool $debug): array
    {
        $lines = ['WARNING: Do not interrupt this script.  Interrupting during rename can cause issues'];
        if ($debug) {
            $lines[] = "DEBUG: There are '" . count($graphs) . "' Graphs to rename";
            foreach ($graphs as $graph) {
                $lines[] = "DEBUG: Graph Name '" . $graph->title . "' starting";
                $lines[] = "DEBUG: Graph Rename Done for Graph '" . $graph->title . "'";
            }

            return [$lines, true];
        }
        if ($graphs === []) {
            return [$lines, true];
        }

        return [[...$lines, str_repeat('.', count($graphs))], false];
    }
}
