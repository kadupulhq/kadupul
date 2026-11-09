<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\IdentityAccess\Infrastructure\Symfony;

use Kadupul\IdentityAccess\Application\Command\UserManagementDenied;
use Kadupul\IdentityAccess\Application\Query\LegacyPasswordAccount;
use Kadupul\IdentityAccess\Application\Query\ListLegacyPasswordAccounts;
use Kadupul\IdentityAccess\Contract\OperatorDatabase;
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
 * Read-only report of local accounts that still store an unsalted MD5
 * password. It shows who they are, never the stored value, and changes
 * nothing: the next successful login rehashes the password.
 */
#[AsCommand(name: 'kadupul:user:legacy-passwords', description: 'List local accounts whose stored password is still an unsalted MD5 hash.')]
final readonly class ListLegacyPasswordsCommand
{
    private const array COLUMNS = ['id', 'username', 'enabled', 'last_login'];

    public function __construct(private ListLegacyPasswordAccounts $list, private ResultRenderer $renderer) {}

    /**
     * @return int a Command::* exit code
     */
    public function __invoke(SymfonyStyle $io, OutputInterface $output, #[MapInput] ListLegacyPasswordsInput $input): int
    {
        $mode = $input->json ? OutputMode::Json : OutputMode::Human;
        if ($input->as === '') {
            return $this->renderer->emptyOperator($io, $output, $mode);
        }
        try {
            $listing = ($this->list)($input->local, $input->as);
        } catch (UserManagementDenied) {
            return $this->renderer->denied($io, $output, $mode);
        } catch (\Throwable $error) {
            return $this->renderer->failed($io, $output, $mode, $error, 'Listing failed');
        }
        $rows = array_map(self::row(...), $listing->accounts);
        if ($mode === OutputMode::Json) {
            $json = ['status' => 'ok', 'database' => $listing->database === OperatorDatabase::Main ? 'main' : 'local', 'count' => count($rows), 'accounts' => $rows];

            return $this->renderer->render(new CommandResult($json, [], Command::SUCCESS), $mode, $output);
        }
        if ($rows === []) {
            $io->success('No local account stores an unsalted MD5 password.');

            return Command::SUCCESS;
        }
        $io->table(self::COLUMNS, array_map(static fn(array $row): array => [
            (string) $row['id'],
            OutputFormatter::escape($row['username']),
            $row['enabled'] ? 'yes' : 'no',
            $row['last_login'] === null ? 'none recorded' : OutputFormatter::escape($row['last_login']),
        ], $rows));
        $io->note(sprintf('%d local account(s) still store an unsalted MD5 password. Each is rehashed on its next successful login.', count($rows)));

        return Command::SUCCESS;
    }

    /** @return array{id: int, username: string, enabled: bool, last_login: ?string} */
    private static function row(LegacyPasswordAccount $account): array
    {
        return ['id' => $account->id, 'username' => $account->username, 'enabled' => $account->enabled, 'last_login' => $account->lastLogin];
    }
}
