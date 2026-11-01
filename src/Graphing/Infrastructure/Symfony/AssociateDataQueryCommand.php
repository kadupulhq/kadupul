<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Graphing\Infrastructure\Symfony;

use Kadupul\Graphing\Application\Command\AssociateDataQuery;
use Kadupul\Graphing\Application\Command\DataQueryAssociation;
use Kadupul\Graphing\Application\Command\GraphManagementDenied;
use Kadupul\Graphing\Application\Command\ListDataQueryChoices;
use Kadupul\Graphing\Application\Port\CatalogChoice;
use Kadupul\Graphing\Application\Port\CatalogDevice;
use Kadupul\Graphing\Domain\ReindexMethod;
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

#[AsCommand(name: 'kadupul:device:add-data-query', description: 'Associate a data query with a device and run it once.')]
final readonly class AssociateDataQueryCommand
{
    private const string UTILITY = 'Kadupul Add Data Query Utility';

    public function __construct(
        private AssociateDataQuery $associate,
        private ListDataQueryChoices $choices,
        private InstallationVersion $version,
        private CliPresentation $presentation,
        private ResultRenderer $renderer,
    ) {}

    public function __invoke(SymfonyStyle $io, OutputInterface $output, #[MapInput] AssociateDataQueryInput $input): int
    {
        $mode = $input->json ? OutputMode::Json : $this->presentation->mode;
        $legacy = new AssociateDataQueryLegacyArguments();
        $early = $this->renderer->preflight($this->presentation->legacy, $this->version, self::UTILITY, $legacy, $input->as, $io, $output, $mode);
        if ($early !== null) {
            return $early;
        }
        // The original checked each value as it read it, before any list mode; the device id is trimmed, the others are not.
        $hostId = $input->hostId === null ? null : trim($input->hostId);
        if ($hostId !== null && !is_numeric($hostId)) {
            return $this->invalid($io, $output, $mode, 'The device id must be numeric.', $legacy->badHostId());
        }
        if ($input->dataQueryId !== null && !is_numeric($input->dataQueryId)) {
            return $this->invalid($io, $output, $mode, 'The data query id must be numeric.', $legacy->badDataQueryId());
        }
        $method = $input->reindexMethod === null ? null : ReindexMethod::parse($input->reindexMethod);
        if ($input->reindexMethod !== null && $method === null) {
            return $this->invalid($io, $output, $mode, 'The reindex method must be 0 to 3, none, uptime, index or fields.', $legacy->badReindexMethod());
        }
        try {
            if ($input->listHosts) {
                return $this->devices($io, $output, $mode, $legacy, $this->choices->devices($input->local, $input->as), $input->noHeaders);
            }
            if ($input->listDataQueries) {
                return $this->queries($io, $output, $mode, $legacy, $this->choices->dataQueries($input->local, $input->as), $input->noHeaders);
            }
            if ($hostId === null) {
                return $this->invalid($io, $output, $mode, 'The --host-id option is required.', $legacy->missingHostId());
            }
            if ($input->dataQueryId === null) {
                return $this->invalid($io, $output, $mode, 'The --data-query-id option is required.', $legacy->missingDataQueryId());
            }
            if ($method === null) {
                return $this->invalid($io, $output, $mode, 'The --reindex-method option is required.', $legacy->missingReindexMethod());
            }
            $association = ($this->associate)($input->local, $input->as, $hostId, $input->dataQueryId, $method);
        } catch (GraphManagementDenied) {
            return $this->renderer->denied($io, $output, $mode);
        } catch (\Throwable $error) {
            return $this->renderer->failed($io, $output, $mode, $error, 'Associating the data query failed');
        }

        return $this->report($io, $output, $mode, $association, $legacy, $hostId, $input->dataQueryId, $method);
    }

    /**
     * @param list<string> $lines what the original printed
     */
    private function invalid(SymfonyStyle $io, OutputInterface $output, OutputMode $mode, string $message, array $lines): int
    {
        return $this->renderer->failure($io, $output, $mode, $message, new CommandResult(['status' => 'invalid', 'error' => $message], $lines, $mode === OutputMode::Legacy ? Command::FAILURE : Command::INVALID));
    }

    /**
     * @param list<CatalogDevice> $devices
     */
    private function devices(SymfonyStyle $io, OutputInterface $output, OutputMode $mode, AssociateDataQueryLegacyArguments $legacy, array $devices, bool $quiet): int
    {
        $rows = array_map(static fn(CatalogDevice $device): array => ['id' => $device->id, 'hostname' => $device->hostname, 'host_template_id' => $device->hostTemplateId, 'description' => $device->description], $devices);
        if ($mode === OutputMode::Human) {
            $io->table(['ID', 'Hostname', 'Template', 'Description'], array_map(array_values(...), $rows));

            return Command::SUCCESS;
        }

        return $this->renderer->render(new CommandResult(['status' => 'ok', 'devices' => $rows], $legacy->devices($devices, $quiet)), $mode, $output);
    }

    /**
     * @param list<CatalogChoice> $queries
     */
    private function queries(SymfonyStyle $io, OutputInterface $output, OutputMode $mode, AssociateDataQueryLegacyArguments $legacy, array $queries, bool $quiet): int
    {
        $rows = array_map(static fn(CatalogChoice $query): array => ['id' => $query->id, 'name' => $query->name], $queries);
        if ($mode === OutputMode::Human) {
            $io->table(['ID', 'Name'], array_map(array_values(...), $rows));

            return Command::SUCCESS;
        }

        return $this->renderer->render(new CommandResult(['status' => 'ok', 'data_queries' => $rows], $legacy->dataQueries($queries, $quiet)), $mode, $output);
    }

    private function report(SymfonyStyle $io, OutputInterface $output, OutputMode $mode, DataQueryAssociation $association, AssociateDataQueryLegacyArguments $legacy, string $hostId, string $dataQueryId, ReindexMethod $method): int
    {
        $exit = $association->succeeded() ? Command::SUCCESS : Command::FAILURE;
        $lines = $legacy->outcome($association, $hostId, $dataQueryId, $method);
        if ($mode === OutputMode::Legacy) {
            return $this->renderer->render(new CommandResult([], $lines, $exit), $mode, $output);
        }
        if ($mode === OutputMode::Json) {
            $json = ['status' => $association->status, 'database' => $association->database === OperatorDatabase::Main ? 'main' : 'local',
                'device' => $association->device === null ? null : ['id' => $association->device->id, 'hostname' => $association->device->hostname],
                'data_query' => $association->dataQuery === null ? null : ['id' => $association->dataQuery->id, 'name' => $association->dataQuery->name],
                'reindex_method' => $method->printed()];

            return $this->renderer->render(new CommandResult($json, [], $exit), $mode, $output);
        }
        if ($association->succeeded()) {
            $io->success($lines[0]);
        } else {
            $io->error($lines[0]);
        }

        return $exit;
    }
}
