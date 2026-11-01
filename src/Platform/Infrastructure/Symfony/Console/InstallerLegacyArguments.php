<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Infrastructure\Symfony\Console;

final class InstallerLegacyArguments extends LegacyArguments
{
    protected function flags(): array
    {
        return self::versionAndHelp() + [
            '--accept-eula' => ['accept-eula', false], '--install' => ['install', false],
            '--force' => ['force', false], '-f' => ['force', false],
            '--automationmode' => ['automationmode', true], '-am' => ['automationmode', true],
            '--automationrange' => ['automationrange', true], '-ar' => ['automationrange', true],
            '--cron' => ['cron', true], '-c' => ['cron', true],
            '--json' => ['configuration', true], '-j' => ['configuration', true],
            '--ini' => ['ini', true], '-i' => ['ini', true],
            '--language' => ['language', true], '--lang' => ['language', true], '-l' => ['language', true],
            '--mode' => ['mode', true], '-m' => ['mode', true],
            '--profile' => ['collection-profile', true], '-p' => ['collection-profile', true],
            '--rrdtool' => ['rrdtool', true], '-r' => ['rrdtool', true],
            '--theme' => ['theme', true], '-t' => ['theme', true],
            '--as' => ['as', true], '--confirm-discovery' => ['confirm-discovery', false],
        ];
    }

    public function invalid(string $argument): array
    {
        return ['ERROR: Invalid installer argument. Run --help.'];
    }

    public function help(): array
    {
        return ['Use bin/console kadupul:install --help.',
            'The default action checks prerequisites without changing installer settings.',
            'Use --install --accept-eula to run; --confirm-discovery explicitly authorizes discovery.'];
    }

    public function translate(array $argv): array
    {
        $plain = [];
        $repeat = [];
        foreach ($argv as $argument) {
            [$flag, $value] = str_contains($argument, '=') ? explode('=', $argument, 2) : [$argument, null];
            if (in_array($flag, ['--path', '--snmp', '--table', '--template'], true)) {
                if ($value === null || !str_contains($value, ':')) {
                    throw new InvalidLegacyArgument($argument);
                }
                $repeat[$flag][] = $value;
            } elseif (in_array($flag, ['--debug', '-d'], true)) {
                // Debugging no longer prints option/credential payloads.
                $repeat['--verbose'] = true;
            } else {
                $plain[] = $argument;
            }
        }
        [$input, $special] = parent::translate($plain);
        return [$input + $repeat, $special];
    }
}
