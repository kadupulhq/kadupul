<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\IdentityAccess\Infrastructure\Symfony;

use Kadupul\IdentityAccess\Application\Command\GrantItemPermission;
use Kadupul\IdentityAccess\Application\Command\GrantOutcome;
use Kadupul\IdentityAccess\Application\Command\GrantReport;
use Kadupul\IdentityAccess\Application\Command\UserManagementDenied;
use Kadupul\IdentityAccess\Contract\OperatorDatabase;
use Kadupul\IdentityAccess\Domain\ItemPermissionRequest;
use Kadupul\IdentityAccess\Domain\ItemType;
use Kadupul\Platform\Infrastructure\Legacy\InstallationVersion;
use Kadupul\Platform\Infrastructure\Symfony\Console\CliPresentation;
use Kadupul\Platform\Infrastructure\Symfony\Console\CommandResult;
use Kadupul\Platform\Infrastructure\Symfony\Console\OutputMode;
use Kadupul\Platform\Infrastructure\Symfony\Console\ResultRenderer;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\MapInput;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'kadupul:permission:add', description: 'Give a user access to a graph, tree, device or graph template.')]
final readonly class AddPermissionCommand
{
    private const string UTILITY = 'Kadupul Add Permissions Utility';

    public function __construct(
        private GrantItemPermission $grant,
        private InstallationVersion $version,
        private CliPresentation $presentation,
        private ResultRenderer $renderer,
    ) {}

    public function __invoke(SymfonyStyle $io, OutputInterface $output, #[MapInput] AddPermissionInput $input): int
    {
        $mode = $input->json ? OutputMode::Json : $this->presentation->mode;
        $legacy = new AddPermsLegacyArguments();
        $early = $this->renderer->preflight($this->presentation->legacy, $this->version, self::UTILITY, $legacy, $input->as, $io, $output, $mode);
        if ($early !== null) {
            return $early;
        }
        $type = $input->itemType === null || $input->itemType === '' ? null : ItemType::fromOption($input->itemType);
        if ($input->itemType !== null && $input->itemType !== '' && $type === null) {
            return $this->invalid($io, $output, $mode, 'The --item-type option must be graph, tree, host or graph_template.');
        }
        $request = ItemPermissionRequest::parse($input->userId, $type, $input->itemId);
        // add_perms.php checked the item and then granted nothing when no user was named.
        // Outside the shim a missing user is a usage error, not a silent success.
        if ($mode !== OutputMode::Legacy && $request->userId === null && $request->invalidUser === null) {
            return $this->invalid($io, $output, $mode, 'The --user-id option is required.');
        }
        try {
            $report = ($this->grant)($request, $input->local, $input->as, !$input->dryRun);
        } catch (UserManagementDenied) {
            return $this->renderer->denied($io, $output, $mode);
        } catch (\Throwable $error) {
            return $this->renderer->failed($io, $output, $mode, $error, 'Permission grant failed');
        }

        return $this->report($io, $output, $mode, $input, $type, $report, $legacy);
    }

    private function invalid(SymfonyStyle $io, OutputInterface $output, OutputMode $mode, string $message): int
    {
        return $this->renderer->failure($io, $output, $mode, $message, new CommandResult(['status' => 'invalid', 'error' => $message], [], Command::INVALID));
    }

    private function report(SymfonyStyle $io, OutputInterface $output, OutputMode $mode, AddPermissionInput $input, ?ItemType $type, GrantReport $report, AddPermsLegacyArguments $legacy): int
    {
        $refused = !$report->succeeded() && $report->outcome !== GrantOutcome::NotGranted;
        if ($mode === OutputMode::Legacy) {
            if ($report->succeeded()) {
                // A grant, and a run that named no user, printed nothing and exited 0.
                return $this->renderer->render(new CommandResult([], [], Command::SUCCESS), $mode, $output);
            }
            // The original ignored a failed write and exited 0; the shim does not.
            $lines = $refused
                ? $legacy->refusal($report->outcome, $this->version->line(self::UTILITY), $input->userId, $type, $input->itemId)
                : ['ERROR: Permission not granted'];

            return $this->renderer->render(new CommandResult([], $lines, Command::FAILURE), $mode, $output);
        }
        $exit = $report->succeeded() ? Command::SUCCESS : ($refused ? Command::INVALID : Command::FAILURE);
        if ($mode === OutputMode::Json) {
            $json = ['status' => $report->succeeded() ? 'ok' : ($refused ? 'invalid' : 'failed'), 'database' => $report->database === OperatorDatabase::Main ? 'main' : 'local',
                'dry_run' => $report->dryRun, 'result' => $report->outcome->value, 'user_id' => $input->userId, 'item_type' => $input->itemType, 'item_id' => $input->itemId];

            return $this->renderer->render(new CommandResult($json, [], $exit), $mode, $output);
        }
        $what = OutputFormatter::escape('user ' . $input->userId . ' access to ' . ($type?->label() ?? '') . ' ' . $input->itemId);
        match ($report->outcome) {
            GrantOutcome::Granted => $io->success('Granted ' . $what . '.'),
            GrantOutcome::Planned => $io->success('Would grant ' . $what . '.'),
            GrantOutcome::NoUser => $io->success('The item exists; no user was named.'),
            GrantOutcome::UserInvalid => $io->getErrorStyle()->error('The user does not exist.'),
            GrantOutcome::ItemTypeMissing => $io->getErrorStyle()->error('The --item-type option is required.'),
            GrantOutcome::ItemIdMissing => $io->getErrorStyle()->error('The --item-id option is required.'),
            GrantOutcome::ItemInvalid => $io->getErrorStyle()->error('The item does not exist.'),
            GrantOutcome::NotGranted => $io->getErrorStyle()->error('The permission was not granted.'),
        };

        return $exit;
    }
}
