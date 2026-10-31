<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Graphing\Infrastructure\Symfony;

use Kadupul\Graphing\Application\Command\GraphRemovalMode;
use Kadupul\Graphing\Application\Command\GraphRemovalReport;
use Kadupul\Graphing\Application\Port\CatalogChoice;
use Kadupul\Graphing\Application\Port\CatalogDevice;
use Kadupul\Platform\Infrastructure\Symfony\Console\InvalidLegacyArgument;
use Kadupul\Platform\Infrastructure\Symfony\Console\LegacyArguments;
use Kadupul\Platform\Infrastructure\Symfony\Console\LegacyRequest;

/**
 * remove_graphs.php read its options with getopt(): a value only after "=", a
 * repeated option as a list, and a value-taking option with none as an empty
 * value that the script refused later with its own message. An option written
 * as "--name=" with nothing after the "=" was not seen at all.
 *
 * --quiet and --graph-type were in its option list but never handled: it printed
 * an error line for each and carried on, so they are accepted and ignored here.
 */
final class RemoveGraphsLegacyArguments extends LegacyArguments
{
    /** Old flags that may be repeated, and what they take. */
    private const array LISTS = ['--host-id' => 'host-id', '--host-template-id' => 'host-template-id', '--graph-template-id' => 'graph-template-id', '--graph-regex' => 'graph-regex'];

    #[\Override]
    protected function flags(): array
    {
        $flags = ['--all' => ['all', false], '--preserve' => ['preserve', false], '--list' => ['list', false], '--list-hosts' => ['list-hosts', false],
            '--list-host-templates' => ['list-host-templates', false], '--list-graph-templates' => ['list-graph-templates', false], '--force' => ['force', false]];
        foreach (self::LISTS as $flag => $option) {
            $flags[$flag] = [$option, true];
        }
        $flags['--quiet'] = ['quiet', false];
        $flags['--graph-type'] = ['graph-type', false];

        return [...$flags, ...self::versionAndHelp()];
    }

    #[\Override]
    public function translate(array $argv): array
    {
        $flags = $this->flags();
        $input = [];
        $lists = [];
        if ($argv === []) {
            return [[], LegacyRequest::Help];
        }
        foreach ($argv as $argument) {
            [$flag, $value] = str_contains($argument, '=') ? explode('=', $argument, 2) : [$argument, ''];
            if (!array_key_exists($flag, $flags)) {
                throw new InvalidLegacyArgument($argument);
            }
            [$option, $takesValue] = $flags[$flag];
            if ($option === null) {
                return [[], $this->special($flag)];
            }
            if ($option === 'quiet' || $option === 'graph-type') {
                // Named in argv order, as getopt() reported them; any value is dropped.
                $lists['ignored'][] = $option;
            } elseif ($takesValue) {
                if (str_contains($argument, '=') && $value === '') {
                    continue;
                }
                $lists[$option][] = $value;
            } else {
                $input['--' . $option] = true;
            }
        }
        foreach ($lists as $option => $values) {
            $input['--' . $option] = $values;
        }

        return [$input, null];
    }

    #[\Override]
    public function help(): array
    {
        return ['', 'usage: remove_graphs.php [--graph-template-id=ID] [--host-template-id=ID]',
            '    [--host-id=ID] [--graph-regex=R] [--all]',
            '    [--list] [--force] [--preserve]', '',
            'Kadupul utility for removing Graphs through the command line.', '',
            'Options:',
            '    --graph-template-id=ID  Select the Graphs of these Graph Templates.',
            '    --host-template-id=ID   Select the Graphs of Devices of these Device Templates.',
            '    --host-id=ID            Select the Graphs of these Devices.',
            '    --graph-regex=R         Select the Graphs whose name matches this expression.',
            '    --all                   Select every Graph.  Ignores the four options above.',
            '    --force                 Actually remove the Graphs, don\'t just count them.',
            '    --preserve              Preserve the Data Sources.  Default is to remove.', '',
            'Any one of the four selectors is enough, and they narrow the selection together.',
            'Repeat a parameter to give it several values, ex: --host-template-id=X --host-template-id=Y',
            'Counting or removing with no selector at all is refused, because that would mean every',
            'Graph; pass --all to say so deliberately.', '',
            'By default, this utility will only report on the number of Graphs that will be removed.  If you',
            'provide the --force option, the Graphs will actually be removed.  If you use the --list option',
            'each of the Graphs to be removed, will be listed.  Options --list and --force are',
            'mutually exclusive.  Because --list removes nothing, it does not need a selector either,',
            'and with none it lists every Graph.', '',
            'List Options:',
            '    --list',
            '    --list-hosts [--host-template-id=ID]',
            '    --list-graph-templates [--host-template-id=ID]',
            '    --list-host-templates', ''];
    }

    /**
     * @param list<string> $names the ignored options that were given
     * @return list<string>
     */
    public function ignored(array $names): array
    {
        $lines = [];
        foreach ($names as $name) {
            array_push($lines, "ERROR: Invalid Argument: ($name)", '');
        }

        return $lines;
    }

    /** @return list<string> */
    public function badRegex(string $pattern): array
    {
        return ["ERROR: Regex specified '$pattern', is not a valid Regex!"];
    }

    /** @return list<string> */
    public function exclusive(): array
    {
        return ['The --list and --force options are mutually exclusive.  Pick on or the other.'];
    }

    /** @return list<string> */
    public function badId(string $label, string $value): array
    {
        return ["FATAL: $label ID $value is invalid"];
    }

    /** @return list<string> */
    public function refusedAll(): array
    {
        return ['ERROR: The options specified will remove all graphs.  To do this you must use the --all option.  Exiting'];
    }

    /** @return list<string> */
    public function nothingFound(): array
    {
        return ['No matching Graphs found.'];
    }

    /**
     * @param list<CatalogDevice> $devices
     * @return list<string>
     */
    public function devices(array $devices): array
    {
        return ['Known Devices: (id, hostname, template, description)',
            ...array_map(static fn(CatalogDevice $device): string => $device->id . "\t" . $device->hostname . "\t" . $device->hostTemplateId . "\t" . $device->description, $devices), ''];
    }

    /**
     * @param list<CatalogChoice> $templates
     * @return list<string>
     */
    public function deviceTemplates(array $templates): array
    {
        return ['Valid Device Templates: (id, name)', "0\tNone",
            ...array_map(static fn(CatalogChoice $template): string => $template->id . "\t" . $template->name, $templates), ''];
    }

    /**
     * @param list<CatalogChoice> $templates
     * @return list<string>
     */
    public function graphTemplates(array $templates): array
    {
        return ['Known Graph Templates: (id, name)',
            ...array_map(static fn(CatalogChoice $template): string => $template->id . "\t" . $template->name, $templates), ''];
    }

    /**
     * What the original printed for a selection that found graphs.
     *
     * @return list<string>
     */
    public function run(GraphRemovalReport $report): array
    {
        $count = count($report->graphs);
        $first = 'There are ' . $count . ' Graphs to Remove.';
        if ($report->mode === GraphRemovalMode::Remove) {
            return [$first . ($report->keepDataSources ? '  Data Sources will be preserved.' : '  Data Sources will be removed if possible.'), 'Delete Operation Completed'];
        }
        $first .= '  Use the --force option to remove these Graphs.';
        if ($report->mode === GraphRemovalMode::Listing) {
            return [$first, "ID\tGraphName", ...array_map(static fn($graph): string => $graph->localGraphId . "\t" . $graph->title, $report->graphs)];
        }

        return [$first, '  Use the --list option to view the list of Graphs'];
    }
}
