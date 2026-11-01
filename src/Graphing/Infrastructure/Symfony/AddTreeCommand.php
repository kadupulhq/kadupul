<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Graphing\Infrastructure\Symfony;

use Kadupul\Graphing\Application\Command\AddTree;
use Kadupul\Graphing\Application\Command\AddTreeNode;
use Kadupul\Graphing\Application\Command\GraphManagementDenied;
use Kadupul\Graphing\Application\Command\ListTreeChoices;
use Kadupul\Graphing\Application\Command\TreeCreation;
use Kadupul\Graphing\Application\Command\TreeLabels;
use Kadupul\Graphing\Application\Command\TreeNodeCreation;
use Kadupul\IdentityAccess\Contract\OperatorDatabase;
use Kadupul\Platform\Infrastructure\Legacy\InstallationVersion;
use Kadupul\Platform\Infrastructure\Symfony\Console\CliPresentation;
use Kadupul\Platform\Infrastructure\Symfony\Console\CommandResult;
use Kadupul\Platform\Infrastructure\Symfony\Console\OutputMode;
use Kadupul\Platform\Infrastructure\Symfony\Console\ResultRenderer;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\MapInput;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/** Console command that replaces add_tree.php. The old argument spelling and output are kept through AddTreeLegacyArguments; the work is done by AddTree and AddTreeNode. */
#[AsCommand(name: 'kadupul:tree:add', description: 'Add a graph tree, or a header, graph, device or site node to one.')]
final readonly class AddTreeCommand
{
    private const string UTILITY = 'Kadupul Add Tree Utility';

    public function __construct(
        private AddTree $addTree,
        private AddTreeNode $addNode,
        private ListTreeChoices $choices,
        private InstallationVersion $version,
        private CliPresentation $presentation,
        private ResultRenderer $renderer,
    ) {}

    public function __invoke(SymfonyStyle $io, OutputInterface $output, #[MapInput] AddTreeInput $input): int
    {
        $mode = $input->json ? OutputMode::Json : $this->presentation->mode;
        $legacy = new AddTreeLegacyArguments();
        $early = $this->renderer->preflight($this->presentation->legacy, $this->version, self::UTILITY, $legacy, $input->as, $io, $output, $mode);
        if ($early !== null) {
            return $early;
        }
        // The script's defaults, and its trim of the text options; the ids stay as typed.
        $type = trim($input->type ?? '');
        $name = trim($input->name ?? '');
        $sortMethod = trim($input->sortMethod ?? 'alpha');
        $nodeType = trim($input->nodeType ?? '');
        $groupingStyle = trim($input->hostGroupStyle ?? '1');
        $treeId = $input->treeId ?? '0';
        $parentNode = $input->parentNode ?? '0';
        $hostId = $input->hostId ?? '0';
        $quiet = $input->noHeaders;
        try {
            // One list per run, in the order the script tried them.
            if ($input->listSites) {
                $sites = $this->choices->sites($input->local, $input->as);

                return $this->list($io, $output, $mode, 'sites', ['ID', 'Name'], $sites, $legacy->sites($sites, $quiet));
            }
            if ($input->listHosts) {
                $devices = $this->choices->devices($input->local, $input->as);

                return $this->list($io, $output, $mode, 'devices', ['ID', 'Hostname', 'Template', 'Description'], $devices, $legacy->devices($devices, $quiet));
            }
            if ($input->listTrees) {
                $trees = $this->choices->trees($input->local, $input->as);

                return $this->list($io, $output, $mode, 'trees', ['ID', 'Sort method', 'Name'], $trees, $legacy->trees($trees, $quiet));
            }
            if ($input->listNodes) {
                $nodes = $this->choices->nodes($input->local, $input->as, $treeId, $nodeType, $parentNode);
                $fromRoot = is_numeric($parentNode) && (float) $parentNode === 0.0;

                return $this->list($io, $output, $mode, 'nodes', ['Type', 'ID', 'Parent', 'Title', 'Attribute'], $nodes, $legacy->nodes($nodes, $quiet, $fromRoot));
            }
            if ($input->listGraphs) {
                if (is_numeric($hostId) && (float) $hostId === 0.0) {
                    return $this->refuse($io, $output, $mode, 'invalid', 'You must supply a device id with --host-id before you can list its graphs.', ['ERROR: You must supply a host_id before you can list its graphs', 'Try --list-hosts'], [], true);
                }

                $graphs = $this->choices->deviceGraphs($input->local, $input->as, $hostId);

                return $this->list($io, $output, $mode, 'graphs', ['ID', 'Name', 'Template'], $graphs, $legacy->deviceGraphs($graphs, $quiet));
            }
            if ($type === 'tree') {
                return $this->tree($io, $output, $mode, $legacy, $input, $name, $sortMethod);
            }
            if ($type === 'node') {
                return $this->node($io, $output, $mode, $legacy, $input, $nodeType, $sortMethod, $name, $groupingStyle);
            }

            return $this->usage($io, $output, $mode, $legacy, 'Unknown type: (' . $type . ')', 'The --type option must be tree or node.');
        } catch (GraphManagementDenied) {
            return $this->renderer->denied($io, $output, $mode);
        } catch (\Throwable $error) {
            return $this->renderer->failed($io, $output, $mode, $error, 'Adding to the tree failed');
        }
    }

    /**
     * @param list<string> $columns
     * @param list<object> $items
     * @param list<string> $lines what the original printed
     */
    private function list(SymfonyStyle $io, OutputInterface $output, OutputMode $mode, string $key, array $columns, array $items, array $lines): int
    {
        $rows = array_map(static fn(object $item): array => get_object_vars($item), $items);
        if ($mode === OutputMode::Human) {
            $io->table($columns, array_map(array_values(...), $rows));

            return Command::SUCCESS;
        }

        return $this->renderer->render(new CommandResult(['status' => 'ok', $key => $rows], $lines), $mode, $output);
    }

    private function tree(SymfonyStyle $io, OutputInterface $output, OutputMode $mode, AddTreeLegacyArguments $legacy, AddTreeInput $input, string $name, string $sortMethod): int
    {
        if ($name === '' || $name === '0') {
            return $this->usage($io, $output, $mode, $legacy, 'You must supply a name with --name', 'The --name option is required for a tree.');
        }
        if (!array_key_exists($sortMethod, TreeLabels::SORT_METHODS)) {
            return $this->usage($io, $output, $mode, $legacy, 'Invalid sort-method: (' . $sortMethod . ')', 'The --sort-method option must be manual, alpha, natural or numeric.');
        }
        $creation = ($this->addTree)($input->local, $input->as, $name, $sortMethod);
        if ($creation->status === TreeCreation::EXISTS) {
            return $this->refuse($io, $output, $mode, $creation->status, 'A tree with that name already exists (tree-id ' . $creation->treeId . ').', ['ERROR: Not adding tree - it already exists - tree-id: (' . $creation->treeId . ')']);
        }
        if (!$creation->succeeded()) {
            return $this->refuse($io, $output, $mode, $creation->status, 'The tree could not be created.', [], ['ERROR: Failed to create the tree.']);
        }
        $line = 'Tree Created - tree-id: (' . $creation->treeId . ')';

        return $this->created($io, $output, $mode, $line, ['status' => 'created', 'database' => $this->database($creation->database), 'tree_id' => $creation->treeId]);
    }

    private function node(SymfonyStyle $io, OutputInterface $output, OutputMode $mode, AddTreeLegacyArguments $legacy, AddTreeInput $input, string $nodeType, string $sortMethod, string $name, string $groupingStyle): int
    {
        if (!array_key_exists($nodeType, TreeLabels::NODE_TYPES)) {
            return $this->usage($io, $output, $mode, $legacy, 'Invalid node-type: (' . $nodeType . ')', 'The --node-type option must be header, graph, host or site.');
        }
        $treeId = $input->treeId ?? '0';
        $parentNode = $input->parentNode ?? '0';
        $graphId = $input->graphId ?? '0';
        $hostId = $input->hostId ?? '0';
        $siteId = $input->siteId ?? '0';
        $creation = ($this->addNode)($input->local, $input->as, $nodeType, $sortMethod, $treeId, $parentNode, $name, $graphId, $hostId, $siteId, $groupingStyle);
        // The script cast the tree id before it reported an unknown tree.
        $tree = (string) $creation->treeId;
        return match ($creation->status) {
            TreeNodeCreation::CREATED => $this->created($io, $output, $mode, 'Added Node node-id: (' . $creation->nodeId . ')', ['status' => 'created', 'database' => $this->database($creation->database), 'tree_id' => $creation->treeId, 'node_id' => $creation->nodeId]),
            TreeNodeCreation::BAD_TREE => $this->refuse($io, $output, $mode, $creation->status, 'You must supply an existing --tree-id before creating a node.', [], ['ERROR: You must supply an existing --tree-id before creating a node.'], true),
            TreeNodeCreation::UNKNOWN_TREE => $this->refuse($io, $output, $mode, $creation->status, 'Tree ' . $tree . ' does not exist.', [], ['ERROR: Tree ' . $tree . ' does not exist. Try --list-trees']),
            TreeNodeCreation::BAD_PARENT => $this->refuse($io, $output, $mode, $creation->status, 'The --parent-node option must be a non-negative integer.', [], ['ERROR: parent-node ' . $parentNode . ' must be a non-negative integer'], true),
            TreeNodeCreation::UNKNOWN_PARENT => $this->refuse($io, $output, $mode, $creation->status, 'Parent node ' . $parentNode . ' does not exist in tree ' . $tree . '.', [], ['ERROR: parent-node ' . $parentNode . ' does not exist in tree ' . $tree . '.']),
            TreeNodeCreation::PARENT_NOT_HEADER => $this->refuse($io, $output, $mode, $creation->status, 'Parent node ' . $parentNode . ' is not a header in tree ' . $tree . '.', [], ['ERROR: parent-node ' . $parentNode . ' is not a header in tree ' . $tree . '.']),
            TreeNodeCreation::NAME_REQUIRED => $this->usage($io, $output, $mode, $legacy, 'You must supply a name with --name', 'The --name option is required for a header node.'),
            TreeNodeCreation::UNKNOWN_GRAPH => $this->refuse($io, $output, $mode, $creation->status, 'Graph ' . $graphId . ' does not exist.', ['ERROR: No such graph-id (' . $graphId . ') exists. Try --list-graphs']),
            TreeNodeCreation::UNKNOWN_SITE => $this->refuse($io, $output, $mode, $creation->status, 'Site ' . $siteId . ' does not exist.', ['ERROR: No such site-id (' . $siteId . ') exists. Try --list-sites']),
            TreeNodeCreation::UNKNOWN_DEVICE => $this->refuse($io, $output, $mode, $creation->status, 'Device ' . $hostId . ' does not exist.', ['ERROR: No such host-id (' . $hostId . ') exists. Try --list-hosts']),
            TreeNodeCreation::BAD_GROUPING => $this->usage($io, $output, $mode, $legacy, 'Host Group Style must be 1 or 2 (Graph Template or Data Query Index)', 'The --host-group-style option must be 1 or 2.'),
            default => $this->refuse($io, $output, $mode, $creation->status, 'The node could not be created.', [], ['ERROR: Failed to create the node.']),
        };
    }

    /**
     * An argument error: the original printed the message and then its help, and exited 1.
     */
    private function usage(SymfonyStyle $io, OutputInterface $output, OutputMode $mode, AddTreeLegacyArguments $legacy, string $line, string $human): int
    {
        return $this->refuse($io, $output, $mode, 'invalid', $human, ['ERROR: ' . $line, ...$mode === OutputMode::Legacy ? [$this->version->line(self::UTILITY), ...$legacy->help()] : []], [], true);
    }

    /**
     * @param list<string> $stdout what the original printed on standard output
     * @param list<string> $stderr what it wrote to standard error
     */
    private function refuse(SymfonyStyle $io, OutputInterface $output, OutputMode $mode, string $status, string $human, array $stdout, array $stderr = [], bool $invalid = false): int
    {
        if ($mode === OutputMode::Legacy) {
            $errors = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
            foreach ($stderr as $line) {
                $errors->writeln($line, OutputInterface::OUTPUT_RAW);
            }
        }

        return $this->renderer->failure($io, $output, $mode, $human, new CommandResult(['status' => $status, 'error' => $human], $stdout, $mode === OutputMode::Legacy || !$invalid ? Command::FAILURE : Command::INVALID));
    }

    /**
     * @param array<string, mixed> $json
     */
    private function created(SymfonyStyle $io, OutputInterface $output, OutputMode $mode, string $line, array $json): int
    {
        if ($mode === OutputMode::Human) {
            $io->success($line);

            return Command::SUCCESS;
        }

        return $this->renderer->render(new CommandResult($json, [$line]), $mode, $output);
    }

    private function database(OperatorDatabase $database): string
    {
        return $database === OperatorDatabase::Main ? 'main' : 'local';
    }
}
