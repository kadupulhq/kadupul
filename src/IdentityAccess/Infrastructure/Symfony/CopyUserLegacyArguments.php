<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\IdentityAccess\Infrastructure\Symfony;

use Kadupul\IdentityAccess\Application\Command\UserCopyOutcome;
use Kadupul\Platform\Infrastructure\Symfony\Console\InvalidLegacyArgument;
use Kadupul\Platform\Infrastructure\Symfony\Console\LegacyArguments;
use Kadupul\Platform\Infrastructure\Symfony\Console\LegacyRequest;

/**
 * copy_user.php took its two usernames by position and looked at flags only in
 * the first slot, so translate() is its own and flags() holds the shim's --as.
 */
final class CopyUserLegacyArguments extends LegacyArguments
{
    #[\Override]
    protected function flags(): array
    {
        return ['--as' => ['as', true], ...self::versionAndHelp()];
    }

    #[\Override]
    public function translate(array $argv): array
    {
        // empty() in the original: '' and '0' both counted as no argument.
        if (($argv[0] ?? '') === '' || $argv[0] === '0') {
            return [[], LegacyRequest::Usage];
        }
        $special = match ($argv[0]) {
            '--help', '-H', '-h' => LegacyRequest::Help,
            '--version', '-V', '-v' => LegacyRequest::Version,
            default => null,
        };
        if ($special !== null) {
            return [[], $special];
        }
        // The original copied to a null or empty username when the second
        // argument was missing or empty, and created an account with no name.
        // The shim prints its usage instead.
        if (($argv[1] ?? '') === '') {
            return [[], LegacyRequest::Usage];
        }
        $input = ['template' => $argv[0], 'new' => $argv[1]];
        // Further arguments were ignored; only the shim's --as is read.
        foreach (array_slice($argv, 2) as $argument) {
            if (!str_starts_with($argument, '--as=')) {
                continue;
            }
            if (strlen($argument) === strlen('--as=')) {
                throw new InvalidLegacyArgument($argument);
            }
            $input['--as'] = substr($argument, strlen('--as='));
        }

        return [$input, null];
    }

    #[\Override]
    public function help(): array
    {
        return ['usage: copy_user.php <template user> <new user>', '',
            'A utility to copy on local Kadupul user and their settings to a new one.', '',
            'NOTE: It is highly recommended that you use the web interface to copy users as',
            'this script will only copy Local Kadupul users.', ''];
    }

    /**
     * What the original printed. Its "template does not exist" check could not
     * fire, because db_fetch_row() returns an empty array rather than null, so
     * every refusal ended at the copy and printed the same line.
     *
     * @return list<string>
     */
    public function report(string $template, string $new, UserCopyOutcome $outcome): array
    {
        $lines = ['Template User: ' . $template, 'New User:      ' . $new, '', 'Copying User...'];

        return $outcome === UserCopyOutcome::Copied ? [...$lines, 'User copied...'] : [...$lines, 'Error: User not copied!', ''];
    }
}
