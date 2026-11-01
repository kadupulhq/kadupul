<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\IdentityAccess\Infrastructure\Symfony;

use Kadupul\IdentityAccess\Application\Command\CopyUser;
use Kadupul\IdentityAccess\Application\Command\UserCopyOutcome;
use Kadupul\IdentityAccess\Application\Command\UserCopyReport;
use Kadupul\IdentityAccess\Application\Command\UserManagementDenied;
use Kadupul\IdentityAccess\Contract\OperatorDatabase;
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

/**
 * Console front end of CopyUser. In legacy output mode it reproduces the copy_user.php messages, which exited 0 on every failure.
 */
#[AsCommand(name: 'kadupul:user:copy', description: 'Copy a local account, with its permissions and settings, to a new account.')]
final readonly class CopyUserCommand
{
    private const string UTILITY = 'Kadupul Copy User Utility';

    public function __construct(
        private CopyUser $copy,
        private InstallationVersion $version,
        private CliPresentation $presentation,
        private ResultRenderer $renderer,
    ) {}

    /**
     * @return int a Command::* exit code
     */
    public function __invoke(SymfonyStyle $io, OutputInterface $output, #[MapInput] CopyUserInput $input): int
    {
        $mode = $input->json ? OutputMode::Json : $this->presentation->mode;
        $legacy = new CopyUserLegacyArguments();
        $early = $this->renderer->preflight($this->presentation->legacy, $this->version, self::UTILITY, $legacy, $input->as, $io, $output, $mode);
        if ($early !== null) {
            return $early;
        }
        $template = $input->template;
        $new = $input->new;
        if ($template === null || $template === '' || $new === null || $new === '') {
            return $this->renderer->failure($io, $output, $mode, 'Both usernames are required.', new CommandResult(['status' => 'invalid', 'error' => 'Both usernames are required'], [], Command::INVALID));
        }
        try {
            $report = ($this->copy)($template, $new, $input->local, $input->as, !$input->dryRun);
        } catch (UserManagementDenied) {
            return $this->renderer->denied($io, $output, $mode);
        } catch (\Throwable $error) {
            return $this->renderer->failed($io, $output, $mode, $error, 'User copy failed');
        }

        return $this->report($io, $output, $mode, $template, $new, $report, $legacy);
    }

    /**
     * Renders the copy outcome in the active output mode and returns the exit code.
     *
     * @return int a Command::* exit code
     */
    private function report(SymfonyStyle $io, OutputInterface $output, OutputMode $mode, string $template, string $new, UserCopyReport $report, CopyUserLegacyArguments $legacy): int
    {
        if ($mode === OutputMode::Legacy) {
            // copy_user.php ended in die() on every failure, which exits 0.
            return $this->renderer->render(new CommandResult([], $legacy->report($template, $new, $report->outcome), Command::SUCCESS), $mode, $output);
        }
        $exit = $report->succeeded() ? Command::SUCCESS : Command::FAILURE;
        if ($mode === OutputMode::Json) {
            $json = ['status' => $report->succeeded() ? 'ok' : 'failed', 'database' => $report->database === OperatorDatabase::Main ? 'main' : 'local', 'dry_run' => $report->dryRun,
                'result' => $report->outcome->value, 'template' => $template, 'new' => $new];

            return $this->renderer->render(new CommandResult($json, [], $exit), $mode, $output);
        }
        $names = OutputFormatter::escape("'" . $template . "' to '" . $new . "'");
        match ($report->outcome) {
            UserCopyOutcome::Copied => $io->success('Copied user ' . $names . '.'),
            UserCopyOutcome::Planned => $io->success('Would copy user ' . $names . '.'),
            UserCopyOutcome::TemplateMissing => $io->getErrorStyle()->error('The template user does not exist.'),
            UserCopyOutcome::NewUserExists => $io->getErrorStyle()->error('The new user already exists; nothing was overwritten.'),
            UserCopyOutcome::NotCopied => $io->getErrorStyle()->error('The user was not copied.'),
        };

        return $exit;
    }
}
