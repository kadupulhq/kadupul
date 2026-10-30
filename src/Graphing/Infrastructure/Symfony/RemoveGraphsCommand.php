<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Graphing\Infrastructure\Symfony;

use Kadupul\Graphing\Application\Command\GraphManagementDenied;
use Kadupul\Graphing\Application\Command\GraphRemovalMode;
use Kadupul\Graphing\Application\Command\GraphRemovalReport;
use Kadupul\Graphing\Application\Command\ListRemovalChoices;
use Kadupul\Graphing\Application\Command\RemoveGraphs;
use Kadupul\Graphing\Application\Port\GraphTitle;
use Kadupul\Graphing\Application\Port\RemovalChoice;
use Kadupul\Graphing\Application\Port\RemovalDevice;
use Kadupul\Graphing\Domain\GraphRemovalFilter;
use Kadupul\IdentityAccess\Contract\OperatorDatabase;
use Kadupul\Platform\Infrastructure\Legacy\InstallationVersion;
use Kadupul\Platform\Infrastructure\Symfony\Console\CliPresentation;
use Kadupul\Platform\Infrastructure\Symfony\Console\CommandResult;
use Kadupul\Platform\Infrastructure\Symfony\Console\OutputMode;
use Kadupul\Platform\Infrastructure\Symfony\Console\ResultRenderer;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\MapInput;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'kadupul:graph:remove', description: 'Count, list or remove graphs by device, template or title.')]
final readonly class RemoveGraphsCommand
{
    private const string UTILITY = 'Kadupul Remove Graphs Utility';

    public function __construct(
        private RemoveGraphs $removeGraphs,
        private ListRemovalChoices $choices,
        private InstallationVersion $version,
        private CliPresentation $presentation,
        private ResultRenderer $renderer,
    ) {}

    public function __invoke(SymfonyStyle $io, OutputInterface $output, #[MapInput] RemoveGraphsInput $input): int
    {
        $mode = $input->json ? OutputMode::Json : $this->presentation->mode;
        $legacy = new RemoveGraphsLegacyArguments();
        $early = $this->renderer->preflight($this->presentation->legacy, $this->version, self::UTILITY, $legacy, $input->as, $io, $output, $mode);
        if ($early !== null) {
            return $early;
        }
        // The original checked in this order: expressions, --list with --force, then each id list.
        $patterns = [];
        foreach ($input->graphRegex as $expression) {
            $pattern = GraphRemovalFilter::pattern($expression);
            if ($pattern === null) {
                return $this->invalid($io, $output, $mode, "ERROR: Regex specified '$expression', is not a valid Regex!", $legacy->badRegex($expression));
            }
            $patterns[] = $pattern;
        }
        if ($input->list && $input->force) {
            return $this->invalid($io, $output, $mode, 'The --list and --force options are mutually exclusive.', $legacy->exclusive());
        }
        foreach ([['Host Template', $input->hostTemplateId], ['Graph Template', $input->graphTemplateId], ['Host', $input->hostId]] as [$label, $values]) {
            foreach ($values as $value) {
                if (GraphRemovalFilter::id($value) === null) {
                    return $this->invalid($io, $output, $mode, "$label ID $value is invalid.", $legacy->badId($label, $value));
                }
            }
        }
        $filter = new GraphRemovalFilter(self::ints($input->hostId), self::ints($input->hostTemplateId), self::ints($input->graphTemplateId), $patterns, $input->all);
        try {
            if ($input->listHosts) {
                return $this->devices($io, $output, $mode, $legacy, $this->choices->devices($input->local, $input->as, $filter->hostTemplateIds));
            }
            if ($input->listHostTemplates) {
                return $this->templates($io, $output, $mode, 'device_templates', 'Device templates', $legacy->deviceTemplates(...), $this->choices->deviceTemplates($input->local, $input->as));
            }
            if ($input->listGraphTemplates) {
                return $this->templates($io, $output, $mode, 'graph_templates', 'Graph templates', $legacy->graphTemplates(...), $this->choices->graphTemplates($input->local, $input->as, $filter->hostTemplateIds));
            }
            $action = $input->force ? GraphRemovalMode::Remove : ($input->list ? GraphRemovalMode::Listing : GraphRemovalMode::Count);
            $report = ($this->removeGraphs)($input->local, $input->as, $filter, $action, $input->preserve);
        } catch (GraphManagementDenied) {
            return $this->renderer->denied($io, $output, $mode);
        } catch (\Throwable $error) {
            return $this->renderer->failed($io, $output, $mode, $error, 'Graph removal failed');
        }

        return $this->report($io, $output, $mode, $report, $legacy);
    }

    /**
     * Ids already checked by GraphRemovalFilter::id(), cast as the original did.
     *
     * @param list<string> $values
     * @return list<int>
     */
    private static function ints(array $values): array
    {
        return array_map(static fn(string $value): int => (int) $value, $values);
    }

    /**
     * @param list<string> $lines what the original printed
     */
    private function invalid(SymfonyStyle $io, OutputInterface $output, OutputMode $mode, string $message, array $lines): int
    {
        return $this->renderer->failure($io, $output, $mode, $message, new CommandResult(['status' => 'invalid', 'error' => $message], $lines, $mode === OutputMode::Legacy ? Command::FAILURE : Command::INVALID));
    }

    /**
     * @param list<RemovalDevice> $devices
     */
    private function devices(SymfonyStyle $io, OutputInterface $output, OutputMode $mode, RemoveGraphsLegacyArguments $legacy, array $devices): int
    {
        $rows = array_map(static fn(RemovalDevice $device): array => ['id' => $device->id, 'hostname' => $device->hostname, 'host_template_id' => $device->hostTemplateId, 'description' => $device->description], $devices);
        if ($mode === OutputMode::Human) {
            $io->table(['ID', 'Hostname', 'Template', 'Description'], array_map(array_values(...), $rows));

            return Command::SUCCESS;
        }

        return $this->renderer->render(new CommandResult(['status' => 'ok', 'devices' => $rows], $legacy->devices($devices)), $mode, $output);
    }

    /**
     * @param callable(list<RemovalChoice>): list<string> $lines
     * @param list<RemovalChoice> $templates
     */
    private function templates(SymfonyStyle $io, OutputInterface $output, OutputMode $mode, string $key, string $title, callable $lines, array $templates): int
    {
        $rows = array_map(static fn(RemovalChoice $template): array => ['id' => $template->id, 'name' => $template->name], $templates);
        if ($mode === OutputMode::Human) {
            $io->section($title);
            $io->table(['ID', 'Name'], array_map(array_values(...), $rows));

            return Command::SUCCESS;
        }

        return $this->renderer->render(new CommandResult(['status' => 'ok', $key => $rows], $lines($templates)), $mode, $output);
    }

    private function report(SymfonyStyle $io, OutputInterface $output, OutputMode $mode, GraphRemovalReport $report, RemoveGraphsLegacyArguments $legacy): int
    {
        $exit = $report->succeeded() ? Command::SUCCESS : Command::FAILURE;
        $database = $report->database === OperatorDatabase::Main ? 'main' : 'local';
        if ($report->refused) {
            return $this->invalid($io, $output, $mode, 'The options specified would remove every graph; pass --all to say so.', $legacy->refusedAll());
        }
        if ($report->graphs === []) {
            return $this->renderer->failure($io, $output, $mode, 'No matching graphs found.', new CommandResult(['status' => 'none', 'database' => $database, 'graphs' => []], $legacy->nothingFound(), Command::FAILURE));
        }
        if ($mode === OutputMode::Legacy) {
            return $this->renderer->render(new CommandResult([], $legacy->run($report), $exit), $mode, $output);
        }
        if ($mode === OutputMode::Json) {
            $json = ['status' => $report->succeeded() ? 'ok' : 'partial', 'database' => $database, 'mode' => strtolower($report->mode->name), 'matched' => count($report->graphs),
                'removed' => $report->removed, 'data_sources' => $report->keepDataSources ? 'kept' : 'removed',
                'graphs' => $report->mode === GraphRemovalMode::Count ? [] : array_map(static fn(GraphTitle $graph): array => ['id' => $graph->localGraphId, 'title' => $graph->title], $report->graphs)];

            return $this->renderer->render(new CommandResult($json, [], $exit), $mode, $output);
        }
        $matched = count($report->graphs);
        if ($report->mode === GraphRemovalMode::Listing) {
            $io->table(['ID', 'Graph'], array_map(static fn(GraphTitle $graph): array => [$graph->localGraphId, $graph->title], $report->graphs));

            return $exit;
        }
        if ($report->mode === GraphRemovalMode::Count) {
            $io->success(sprintf('%d graph(s) would be removed. Use --force to remove them or --list to see them.', $matched));

            return $exit;
        }

        return $this->renderer->summary($io, sprintf('Removed %d of %d graph(s); data sources %s', $report->removed, $matched, $report->keepDataSources ? 'kept' : 'removed where possible'), $matched - $report->removed);
    }
}
