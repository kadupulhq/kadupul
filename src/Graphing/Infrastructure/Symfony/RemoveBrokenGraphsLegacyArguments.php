<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Graphing\Infrastructure\Symfony;

use Kadupul\Graphing\Application\Command\BrokenGraphReport;
use Kadupul\Platform\Infrastructure\Symfony\Console\LegacyArguments;

/** Argument spelling and help text of remove_broken_graphs.php. */
final class RemoveBrokenGraphsLegacyArguments extends LegacyArguments
{
    private const string RULE = '-------------------------------------------------------------------------------------';

    #[\Override]
    protected function flags(): array
    {
        return ['--debug' => ['debug', false], '--report' => ['report', false], '--remove' => ['remove', false], ...self::versionAndHelp()];
    }

    #[\Override]
    public function help(): array
    {
        return ['', 'usage: remove_broken_graphs.php [--report | --remove] [-d|--debug]', '',
            'A utility to remove broken graphs from Kadupul.  A broken Graph is one that.',
            'lacks Data Sources.  This can happen from time to time when working with and modifying templates.',
            "It's important to periodically run this utility especially on larger systems.", '',
            'Optional:',
            '--report  - Display the Graph Templates and Count of Broken Graphs',
            '--remove  - Remove the Broken Graphs as Reported using the --report Option',
            '--debug   - Display verbose output during execution', ''];
    }

    /** @return list<string> */
    public function exclusive(): array
    {
        return ['ERROR: The options --report and --remove are mutually exclusive'];
    }

    /**
     * What the original printed: the query notice, the count, and for each graph
     * template either its broken graphs, wrapped to the terminal, or the removal.
     *
     * @return list<string>
     */
    public function run(BrokenGraphReport $report, int $columns): array
    {
        $groups = $report->groups;
        $lines = ['Running Query to find Broken Graphs.  This Query may run for some time depending the number of Graph in your System',
            $groups === [] ? 'There are no Graph Templates with Broken Graphs.' : 'There are ' . count($groups) . ' Graph Templates with Broken Graphs.'];
        if ($groups === []) {
            return $lines;
        }
        array_push($lines, self::RULE, $report->removing ? 'Broken Graph Report Removal' : 'Broken Graph Report', self::RULE);
        foreach ($groups as $group) {
            $count = count($group->graphs);
            if (!$report->removing) {
                $ids = implode(', ', array_map(static fn($graph): int => $graph->localGraphId, $group->graphs));
                array_push($lines, sprintf("Graph Template: %s, Contains '%s' broken Graphs", $group->name, $count), 'Graphs:', self::RULE, wordwrap($ids, $columns - 5, "\n"), self::RULE);

                continue;
            }
            array_push($lines, self::RULE, sprintf("Started removing '%s' broken Graphs for Graph Template %s", $count, $group->name), self::RULE);
            foreach ($group->graphs as $graph) {
                $lines[] = sprintf('Removing Graph %s, "%s"', $graph->title, $graph->localGraphId);
            }
            array_push($lines, self::RULE, sprintf('Completed removing Graphs for Graph Template %s', $group->name));
        }

        return $lines;
    }
}
