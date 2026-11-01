<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Graphing\Infrastructure\Symfony;

use Kadupul\Graphing\Application\Command\GraphManagementDenied;
use Kadupul\Graphing\Application\Command\GraphRepairReport;
use Kadupul\Graphing\Application\Command\RepairGraphItems;
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

/** Console command that replaces repair_graphs.php. The old argument spelling and output are kept through RepairGraphItemsLegacyArguments; the work is done by RepairGraphItems. */
#[AsCommand(name: 'kadupul:graph:repair-items', description: 'Point graph items back at their device\'s data source RRD item.')]
final readonly class RepairGraphItemsCommand
{
    private const string UTILITY = 'Kadupul Graph Repair Tool';

    public function __construct(
        private RepairGraphItems $repair,
        private InstallationVersion $version,
        private CliPresentation $presentation,
        private ResultRenderer $renderer,
    ) {}

    public function __invoke(SymfonyStyle $io, OutputInterface $output, #[MapInput] RepairGraphItemsInput $input): int
    {
        $mode = $input->json ? OutputMode::Json : $this->presentation->mode;
        $legacy = new RepairGraphItemsLegacyArguments();
        $early = $this->renderer->preflight($this->presentation->legacy, $this->version, self::UTILITY, $legacy, $input->as, $io, $output, $mode);
        if ($early !== null) {
            return $early;
        }
        // The original refused a run that neither previewed nor executed; bin/console reports instead.
        if ($mode === OutputMode::Legacy && !$input->execute && !$input->showSql) {
            return $this->renderer->render(new CommandResult([], $legacy->help(), Command::FAILURE), $mode, $output);
        }
        $ids = [];
        foreach (['dataTemplateId' => 'data-template-id', 'graphTemplateId' => 'graph-template-id', 'hostId' => 'host-id'] as $property => $name) {
            $value = $input->$property;
            if ($value === null && $property === 'hostId') {
                continue;
            }
            if ($value === null || preg_match('/^\d{1,18}$/D', $value) !== 1) {
                $message = $value === null ? sprintf('You must supply a valid %s.', $name) : sprintf('The --%s option must be a whole number.', $name);

                return $this->renderer->failure($io, $output, $mode, $message, new CommandResult(['status' => 'invalid', 'error' => $message], ['ERROR: You must supply a valid ' . $name . '!'], $mode === OutputMode::Legacy ? Command::FAILURE : Command::INVALID));
            }
            $ids[$property] = (int) $value;
        }
        try {
            $report = ($this->repair)($ids['graphTemplateId'], $ids['dataTemplateId'], $ids['hostId'] ?? null, $input->local, $input->as, $input->execute);
        } catch (GraphManagementDenied) {
            return $this->renderer->denied($io, $output, $mode);
        } catch (\Throwable $error) {
            return $this->renderer->failed($io, $output, $mode, $error, 'Graph repair failed');
        }

        return $this->report($io, $output, $mode, $input, $report, $legacy);
    }

    private function report(SymfonyStyle $io, OutputInterface $output, OutputMode $mode, RepairGraphItemsInput $input, GraphRepairReport $report, RepairGraphItemsLegacyArguments $legacy): int
    {
        if ($mode === OutputMode::Legacy) {
            return $this->renderer->render(new CommandResult([], $legacy->run($input->execute, $input->showSql, $report->checked, $report->entries), Command::SUCCESS), $mode, $output);
        }
        if ($mode === OutputMode::Json) {
            $json = ['status' => 'ok', 'database' => $report->database === OperatorDatabase::Main ? 'main' : 'local', 'executed' => $report->executed,
                'checked' => $report->checked, 'repointed' => $report->repointed(),
                'entries' => array_map(static fn($entry): array => $entry->isMissingRrd()
                    ? ['data_source_id' => $entry->dataSourceId, 'problem' => 'no-rrd-item']
                    : ['host_id' => $entry->hostId, 'graph_id' => $entry->graphId, 'items' => $entry->itemIds, 'from' => $entry->oldTaskItemIds, 'to' => $entry->rrdId, 'sql' => $input->showSql ? $entry->sql : null], $report->entries)];

            return $this->renderer->render(new CommandResult($json, [], Command::SUCCESS), $mode, $output);
        }
        $io->success(sprintf('%s %d of %d graph(s).', $report->executed ? 'Repointed' : 'Would repoint', $report->repointed(), $report->checked));
        if ($input->showSql) {
            foreach ($report->entries as $entry) {
                if (!$entry->isMissingRrd()) {
                    $io->writeln($entry->sql . ';', OutputInterface::OUTPUT_RAW);
                }
            }
        }

        return Command::SUCCESS;
    }
}
