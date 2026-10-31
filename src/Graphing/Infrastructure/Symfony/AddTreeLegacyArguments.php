<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Graphing\Infrastructure\Symfony;

use Kadupul\Graphing\Application\Port\CatalogChoice;
use Kadupul\Graphing\Application\Port\CatalogDevice;
use Kadupul\Graphing\Application\Port\HostGraphRow;
use Kadupul\Graphing\Application\Port\TreeNodeRow;
use Kadupul\Graphing\Application\Port\TreeSummary;
use Kadupul\Graphing\Application\Command\TreeLabels;
use Kadupul\Platform\Infrastructure\Symfony\Console\InvalidLegacyArgument;
use Kadupul\Platform\Infrastructure\Symfony\Console\LegacyArguments;
use Kadupul\Platform\Infrastructure\Symfony\Console\LegacyRequest;

/**
 * add_tree.php split each argument at the first "=" that was not its first
 * character and kept the text after it, empty for a flag written without one; a
 * flag it knew took any "=value", and --version or --help ended the run where
 * it stood. --quiet is the console's own option, so it reaches the command as --no-headers.
 */
final class AddTreeLegacyArguments extends LegacyArguments
{
    #[\Override]
    protected function flags(): array
    {
        return ['--type' => ['type', true], '--name' => ['name', true], '--sort-method' => ['sort-method', true], '--parent-node' => ['parent-node', true],
            '--tree-id' => ['tree-id', true], '--node-type' => ['node-type', true], '--graph-id' => ['graph-id', true], '--host-id' => ['host-id', true],
            '--site-id' => ['site-id', true], '--host-group-style' => ['host-group-style', true], '--quiet' => ['no-headers', false],
            '--list-hosts' => ['list-hosts', false], '--list-trees' => ['list-trees', false], '--list-nodes' => ['list-nodes', false],
            '--list-graphs' => ['list-graphs', false], '--list-sites' => ['list-sites', false], ...self::versionAndHelp()];
    }

    #[\Override]
    public function translate(array $argv): array
    {
        if ($argv === []) {
            return [[], LegacyRequest::Help];
        }
        $flags = $this->flags();
        $input = [];
        foreach ($argv as $argument) {
            [$flag, $value] = strpos($argument, '=') > 0 ? explode('=', $argument, 2) : [$argument, ''];
            if (!array_key_exists($flag, $flags)) {
                throw new InvalidLegacyArgument($argument);
            }
            [$option, $takesValue] = $flags[$flag];
            if ($option === null) {
                return [[], $this->special($flag)];
            }
            $input['--' . $option] = $takesValue ? $value : true;
        }

        return [$input, null];
    }

    #[\Override]
    public function invalid(string $argument): array
    {
        return ['ERROR: Invalid Argument: (' . (strpos($argument, '=') > 0 ? explode('=', $argument, 2)[0] : $argument) . ')', ''];
    }

    #[\Override]
    public function help(): array
    {
        return ['', 'usage: add_tree.php  --type=[tree|node] [type-options] [--quiet]', '',
            'Tree options:',
            '    --name=[Tree Name]',
            '    --sort-method=[manual|alpha|natural|numeric]', '',
            'Node options:',
            '    --node-type=[header|site|host|graph]',
            '    --tree-id=[ID]',
            '    [--parent-node=[ID] [Node Type Options]]', '',
            'Header node options:',
            '    --name=[Name]', '',
            'Site node options:',
            '    --site-id=[ID]',
            'Host node options:',
            '    --host-id=[ID]',
            '    [--host-group-style=[1|2]]',
            '    (host group styles:',
            '     1 = Graph Template,',
            '     2 = Data Query Index)', '',
            'Graph node options:',
            '    --graph-id=[ID]', '',
            'List Options:',
            '    --list-sites',
            '    --list-hosts',
            '    --list-trees',
            '    --list-nodes --tree-id=[ID]',
            '    --list-graphs --host-id=[ID]'];
    }

    /**
     * @param list<CatalogChoice> $sites
     * @return list<string>
     */
    public function sites(array $sites, bool $quiet): array
    {
        return $this->list('Known Sites: (id, name)', array_map(static fn(CatalogChoice $site): string => $site->id . "\t" . $site->name, $sites), $quiet);
    }

    /**
     * @param list<CatalogDevice> $devices
     * @return list<string>
     */
    public function devices(array $devices, bool $quiet): array
    {
        return $this->list('Known Devices: (id, hostname, template, description)', array_map(static fn(CatalogDevice $device): string => $device->id . "\t" . $device->hostname . "\t" . $device->hostTemplateId . "\t" . $device->description, $devices), $quiet);
    }

    /**
     * @param list<TreeSummary> $trees
     * @return list<string>
     */
    public function trees(array $trees, bool $quiet): array
    {
        return $this->list('Known Trees: (id, sort method, name)', array_map(static fn(TreeSummary $tree): string => $tree->id . "\t" . TreeLabels::sort($tree->sortType) . "\t" . $tree->name, $trees), $quiet);
    }

    /**
     * The heading shows only when the walk started at the root, which the original decided by comparing --parent-node with 0.
     *
     * @param list<TreeNodeRow> $nodes
     * @return list<string>
     */
    public function nodes(array $nodes, bool $quiet, bool $fromRoot): array
    {
        $lines = array_map(static function (TreeNodeRow $node): string {
            $line = TreeLabels::kind($node->kind) . "\t" . $node->id . "\t" . $node->parent . "\t" . $node->title . "\t";
            if ($node->attribute !== null) {
                $line .= ($node->kind === TreeNodeRow::HOST ? TreeLabels::grouping($node->attribute) : TreeLabels::sort($node->attribute)) . "\t";
            }

            return $line;
        }, $nodes);

        return $fromRoot ? $this->list('Known Tree Nodes: (type, id, parentid, title, attribs)', $lines, $quiet) : $lines;
    }

    /**
     * @param list<HostGraphRow> $graphs
     * @return list<string>
     */
    public function deviceGraphs(array $graphs, bool $quiet): array
    {
        return $this->list('Known Device Graphs: (id, name, template)', array_map(static fn(HostGraphRow $graph): string => $graph->id . "\t" . $graph->name . "\t" . $graph->template . "\t", $graphs), $quiet);
    }

    /**
     * @param list<string> $rows
     * @return list<string>
     */
    private function list(string $heading, array $rows, bool $quiet): array
    {
        return [...$quiet ? [] : [$heading], ...$rows, ...$quiet ? [] : ['']];
    }
}
