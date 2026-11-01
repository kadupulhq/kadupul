<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Graphing\Infrastructure\Symfony;

use Kadupul\Graphing\Application\Command\AddDataSource;
use Kadupul\Graphing\Application\Command\DataSourceAddition;
use Kadupul\Graphing\Application\Command\GraphManagementDenied;
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

#[AsCommand(name: 'kadupul:data-source:add', description: 'Add a data source from a data template, on a device or on none.')]
final readonly class AddDataSourceCommand
{
    private const string UTILITY = 'Kadupul Add Data Source';

    public function __construct(
        private AddDataSource $add,
        private InstallationVersion $version,
        private CliPresentation $presentation,
        private ResultRenderer $renderer,
    ) {}

    public function __invoke(SymfonyStyle $io, OutputInterface $output, #[MapInput] AddDataSourceInput $input): int
    {
        $mode = $input->json ? OutputMode::Json : $this->presentation->mode;
        $legacy = new AddDataSourceLegacyArguments();
        $early = $this->renderer->preflight($this->presentation->legacy, $this->version, self::UTILITY, $legacy, $input->as, $io, $output, $mode);
        if ($early !== null) {
            return $early;
        }
        // The original checked each value as it read it; the device id is trimmed, the template id is not.
        $hostId = $input->hostId === null ? null : trim($input->hostId);
        if ($hostId !== null && !is_numeric($hostId)) {
            return $this->invalid($io, $output, $mode, 'The device id must be numeric.', $legacy->badHostId());
        }
        if ($input->dataTemplateId !== null && !is_numeric($input->dataTemplateId)) {
            return $this->invalid($io, $output, $mode, 'The data template id must be numeric.', $legacy->badDataTemplateId());
        }
        if ($hostId === null) {
            return $this->invalid($io, $output, $mode, 'The --host-id option is required.', $legacy->missingHostId());
        }
        if ($input->dataTemplateId === null) {
            return $this->invalid($io, $output, $mode, 'The --data-template-id option is required.', $legacy->missingDataTemplateId());
        }
        try {
            $addition = ($this->add)($input->local, $input->as, $hostId, $input->dataTemplateId);
        } catch (GraphManagementDenied) {
            return $this->renderer->denied($io, $output, $mode);
        } catch (\Throwable $error) {
            return $this->renderer->failed($io, $output, $mode, $error, 'Adding the data source failed');
        }

        return $this->report($io, $output, $mode, $addition, $legacy, $hostId, $input->dataTemplateId);
    }

    /**
     * @param list<string> $lines what the original printed
     */
    private function invalid(SymfonyStyle $io, OutputInterface $output, OutputMode $mode, string $message, array $lines): int
    {
        return $this->renderer->failure($io, $output, $mode, $message, new CommandResult(['status' => 'invalid', 'error' => $message], $lines, $mode === OutputMode::Legacy ? Command::FAILURE : Command::INVALID));
    }

    private function report(SymfonyStyle $io, OutputInterface $output, OutputMode $mode, DataSourceAddition $addition, AddDataSourceLegacyArguments $legacy, string $hostId, string $dataTemplateId): int
    {
        $exit = $addition->succeeded() ? Command::SUCCESS : Command::FAILURE;
        $lines = $legacy->outcome($addition, $hostId, $dataTemplateId);
        if ($mode === OutputMode::Legacy) {
            return $this->renderer->render(new CommandResult([], $lines, $exit), $mode, $output);
        }
        if ($mode === OutputMode::Json) {
            $json = ['status' => $addition->status, 'database' => $addition->database === OperatorDatabase::Main ? 'main' : 'local',
                'data_source_id' => $addition->succeeded() ? $addition->id : null,
                'device' => $addition->device === null ? null : ['id' => $addition->device->id, 'hostname' => $addition->device->name],
                'data_template' => $addition->dataTemplate === null ? null : ['id' => $addition->dataTemplate->id, 'name' => $addition->dataTemplate->name]];

            return $this->renderer->render(new CommandResult($json, [], $exit), $mode, $output);
        }
        if ($addition->succeeded()) {
            $io->success($lines[0]);
        } else {
            $io->error($lines[0]);
        }

        return $exit;
    }
}
