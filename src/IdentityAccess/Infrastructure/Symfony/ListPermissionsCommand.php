<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\IdentityAccess\Infrastructure\Symfony;

use Kadupul\IdentityAccess\Application\Command\ListOutcome;
use Kadupul\IdentityAccess\Application\Command\ListPermissionTargets;
use Kadupul\IdentityAccess\Application\Command\PermissionListing;
use Kadupul\IdentityAccess\Application\Command\UserManagementDenied;
use Kadupul\IdentityAccess\Contract\OperatorDatabase;
use Kadupul\IdentityAccess\Domain\ListTarget;
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

/** Console front end of ListPermissionTargets, replacing the --list-* options of add_perms.php. */
#[AsCommand(name: 'kadupul:permission:list', description: 'List the users, trees, devices, graphs or graph templates a permission can name.')]
final readonly class ListPermissionsCommand
{
    private const string UTILITY = 'Kadupul Add Permissions Utility';

    public function __construct(
        private ListPermissionTargets $list,
        private InstallationVersion $version,
        private CliPresentation $presentation,
        private ResultRenderer $renderer,
    ) {}

    /**
     * @return int a Command::* exit code
     */
    public function __invoke(SymfonyStyle $io, OutputInterface $output, #[MapInput] ListPermissionsInput $input): int
    {
        $mode = $input->json ? OutputMode::Json : $this->presentation->mode;
        $legacy = new AddPermsLegacyArguments();
        $early = $this->renderer->preflight($this->presentation->legacy, $this->version, self::UTILITY, $legacy, $input->as, $io, $output, $mode);
        if ($early !== null) {
            return $early;
        }
        $target = ListTarget::tryFrom($input->target ?? '');
        if ($target === null) {
            $message = 'Choose what to list: users, trees, hosts, graphs or graph-templates.';

            return $this->renderer->failure($io, $output, $mode, $message, new CommandResult(['status' => 'invalid', 'error' => $message], [], Command::INVALID));
        }
        try {
            $listing = ($this->list)($target, $input->hostId, $input->local, $input->as);
        } catch (UserManagementDenied) {
            return $this->renderer->denied($io, $output, $mode);
        } catch (\Throwable $error) {
            return $this->renderer->failed($io, $output, $mode, $error, 'Listing failed');
        }

        return $this->report($io, $output, $mode, $input, $listing, $legacy);
    }

    /**
     * Renders the listing in the active output mode and returns the exit code.
     *
     * @return int a Command::* exit code
     */
    private function report(SymfonyStyle $io, OutputInterface $output, OutputMode $mode, ListPermissionsInput $input, PermissionListing $listing, AddPermsLegacyArguments $legacy): int
    {
        if ($mode === OutputMode::Legacy) {
            // The version line is needed only for the help that follows a bad device.
            $versionLine = $listing->outcome === ListOutcome::HostInvalid ? $this->version->line(self::UTILITY) : '';
            [$lines, $newline] = $legacy->listing($listing, $input->noHeadings, $versionLine, $input->hostId);
            $exit = $listing->outcome === ListOutcome::HostInvalid ? Command::FAILURE : Command::SUCCESS;

            return $this->renderer->render(new CommandResult([], $lines, $exit, $newline), $mode, $output);
        }
        $failure = match ($listing->outcome) {
            ListOutcome::Listed => null,
            ListOutcome::HostInvalid => ['invalid', 'The --host-id option must name an existing device.', Command::INVALID],
            ListOutcome::NotImplemented => ['failed', 'Listing groups is not implemented.', Command::FAILURE],
        };
        if ($failure !== null) {
            return $this->renderer->failure($io, $output, $mode, $failure[1], new CommandResult(['status' => $failure[0], 'target' => $listing->target->value, 'error' => $failure[1]], [], $failure[2]));
        }
        $columns = $listing->target->columns();
        if ($mode === OutputMode::Json) {
            $json = ['status' => 'ok', 'database' => $listing->database === OperatorDatabase::Main ? 'main' : 'local', 'target' => $listing->target->value,
                'rows' => array_map(static fn(array $row): array => array_combine($columns, $row), $listing->rows)];

            return $this->renderer->render(new CommandResult($json, [], Command::SUCCESS), $mode, $output);
        }
        if ($listing->rows === []) {
            $io->note('Nothing to list.');

            return Command::SUCCESS;
        }
        $io->table($columns, array_map(static fn(array $row): array => array_map(OutputFormatter::escape(...), $row), $listing->rows));

        return Command::SUCCESS;
    }
}
