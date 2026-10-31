<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Graphing\Infrastructure\Symfony;

use Kadupul\Graphing\Application\Command\AttachGraphTemplate;
use Kadupul\Graphing\Application\Command\GraphManagementDenied;
use Kadupul\Graphing\Application\Command\GraphTemplateAttachment;
use Kadupul\Graphing\Application\Command\ListAttachmentChoices;
use Kadupul\Graphing\Application\Port\CatalogChoice;
use Kadupul\Graphing\Application\Port\CatalogDevice;
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

#[AsCommand(name: 'kadupul:device:add-graph-template', description: 'Associate a graph template with a device.')]
final readonly class AttachGraphTemplateCommand
{
    private const string UTILITY = 'Kadupul Add Graph Template Utility';

    public function __construct(
        private AttachGraphTemplate $attach,
        private ListAttachmentChoices $choices,
        private InstallationVersion $version,
        private CliPresentation $presentation,
        private ResultRenderer $renderer,
    ) {}

    public function __invoke(SymfonyStyle $io, OutputInterface $output, #[MapInput] AttachGraphTemplateInput $input): int
    {
        $mode = $input->json ? OutputMode::Json : $this->presentation->mode;
        $legacy = new AttachGraphTemplateLegacyArguments();
        $early = $this->renderer->preflight($this->presentation->legacy, $this->version, self::UTILITY, $legacy, $input->as, $io, $output, $mode);
        if ($early !== null) {
            return $early;
        }
        // The original checked the ids as it read them, before any list mode; the host id is trimmed, the template id is not.
        $hostId = $input->hostId === null ? null : trim($input->hostId);
        if ($hostId !== null && !is_numeric($hostId)) {
            return $this->invalid($io, $output, $mode, 'The device id must be numeric.', $legacy->badHostId());
        }
        if ($input->graphTemplateId !== null && !is_numeric($input->graphTemplateId)) {
            return $this->invalid($io, $output, $mode, 'The graph template id must be numeric.', $legacy->badGraphTemplateId());
        }
        try {
            if ($input->listHosts) {
                return $this->devices($io, $output, $mode, $legacy, $this->choices->devices($input->local, $input->as), $input->noHeaders);
            }
            if ($input->listGraphTemplates) {
                return $this->templates($io, $output, $mode, $legacy, $this->choices->graphTemplates($input->local, $input->as), $input->noHeaders);
            }
            if ($hostId === null) {
                return $this->invalid($io, $output, $mode, 'The --host-id option is required.', $legacy->missingHostId());
            }
            if ($input->graphTemplateId === null) {
                return $this->invalid($io, $output, $mode, 'The --graph-template-id option is required.', $legacy->missingGraphTemplateId());
            }
            $attachment = ($this->attach)($input->local, $input->as, $hostId, $input->graphTemplateId);
        } catch (GraphManagementDenied) {
            return $this->renderer->denied($io, $output, $mode);
        } catch (\Throwable $error) {
            return $this->renderer->failed($io, $output, $mode, $error, 'Associating the graph template failed');
        }

        return $this->report($io, $output, $mode, $attachment, $legacy, $hostId, $input->graphTemplateId);
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
    private function devices(SymfonyStyle $io, OutputInterface $output, OutputMode $mode, AttachGraphTemplateLegacyArguments $legacy, array $devices, bool $quiet): int
    {
        $rows = array_map(static fn(CatalogDevice $device): array => ['id' => $device->id, 'hostname' => $device->hostname, 'host_template_id' => $device->hostTemplateId, 'description' => $device->description], $devices);
        if ($mode === OutputMode::Human) {
            $io->table(['ID', 'Hostname', 'Template', 'Description'], array_map(array_values(...), $rows));

            return Command::SUCCESS;
        }

        return $this->renderer->render(new CommandResult(['status' => 'ok', 'devices' => $rows], $legacy->devices($devices, $quiet)), $mode, $output);
    }

    /**
     * @param list<CatalogChoice> $templates
     */
    private function templates(SymfonyStyle $io, OutputInterface $output, OutputMode $mode, AttachGraphTemplateLegacyArguments $legacy, array $templates, bool $quiet): int
    {
        $rows = array_map(static fn(CatalogChoice $template): array => ['id' => $template->id, 'name' => $template->name], $templates);
        if ($mode === OutputMode::Human) {
            $io->table(['ID', 'Name'], array_map(array_values(...), $rows));

            return Command::SUCCESS;
        }

        return $this->renderer->render(new CommandResult(['status' => 'ok', 'graph_templates' => $rows], $legacy->graphTemplates($templates, $quiet)), $mode, $output);
    }

    private function report(SymfonyStyle $io, OutputInterface $output, OutputMode $mode, GraphTemplateAttachment $attachment, AttachGraphTemplateLegacyArguments $legacy, string $hostId, string $graphTemplateId): int
    {
        $exit = $attachment->succeeded() ? Command::SUCCESS : Command::FAILURE;
        $lines = $legacy->outcome($attachment, $hostId, $graphTemplateId);
        if ($mode === OutputMode::Legacy) {
            return $this->renderer->render(new CommandResult([], $lines, $exit), $mode, $output);
        }
        if ($mode === OutputMode::Json) {
            $json = ['status' => $attachment->status, 'database' => $attachment->database === OperatorDatabase::Main ? 'main' : 'local',
                'device' => $attachment->device === null ? null : ['id' => $attachment->device->id, 'hostname' => $attachment->device->hostname],
                'graph_template' => $attachment->template === null ? null : ['id' => $attachment->template->id, 'name' => $attachment->template->name]];

            return $this->renderer->render(new CommandResult($json, [], $exit), $mode, $output);
        }
        if ($attachment->succeeded()) {
            $io->success($lines[0]);
        } else {
            $io->error($lines[0]);
        }

        return $exit;
    }
}
