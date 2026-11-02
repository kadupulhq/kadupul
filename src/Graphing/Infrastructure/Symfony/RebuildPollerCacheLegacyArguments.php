<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Graphing\Infrastructure\Symfony;

use Kadupul\Platform\Infrastructure\Symfony\Console\InvalidLegacyArgument;
use Kadupul\Platform\Infrastructure\Symfony\Console\LegacyArguments;

/**
 * The arguments of cli/rebuild_poller_cache.php. The script split each
 * argument at the first "=" that was not its first character, gave a flag
 * without one an empty value, let a later value replace an earlier one, and
 * stopped at the first value it refused. A flag that takes no value ignored
 * any "=value". A run with no arguments rebuilt everything.
 */
final class RebuildPollerCacheLegacyArguments extends LegacyArguments
{
    /**
     * The --threads argument the script refused after an earlier valid one,
     * and that earlier value, which its message quoted; see invalidOutput().
     *
     * @var ?array{0: string, 1: int}
     */
    private ?array $refusedThreads = null;

    /**
     * Every argument the script's switch knew.
     *
     * @return array<string, array{0: ?string, 1: bool}>
     */
    #[\Override]
    protected function flags(): array
    {
        return [
            '--host-id' => ['host-id', true],
            '--host-template-id' => ['host-template-id', true],
            '--data-template-id' => ['data-template-id', true],
            '--type' => ['type', true],
            '--threads' => ['threads', true],
            '--child' => ['child', true],
            '--force' => ['force', false],
            '-d' => ['debug', false],
            '--debug' => ['debug', false],
            ...self::versionAndHelp(),
        ];
    }

    /**
     * @param list<string> $argv
     * @return array{0: array<string, mixed>, 1: ?\Kadupul\Platform\Infrastructure\Symfony\Console\LegacyRequest}
     * @throws InvalidLegacyArgument for an argument the script did not know, or a refused --threads after a valid one
     */
    #[\Override]
    public function translate(array $argv): array
    {
        $flags = $this->flags();
        $input = [];
        $threads = null;
        foreach ($argv as $argument) {
            [$flag, $value] = strpos($argument, '=') > 0 ? explode('=', $argument, 2) : [$argument, ''];
            if (!array_key_exists($flag, $flags)) {
                throw new InvalidLegacyArgument($argument);
            }
            [$option, $takesValue] = $flags[$flag];
            if ($option === null) {
                return [[], $this->special($flag)];
            }
            if (!$takesValue) {
                $input['--' . $option] = true;

                continue;
            }
            if ($flag === '--threads' && $threads !== null && self::threads($value) === null) {
                // The script quoted the value an earlier --threads had set, which only this map still knows.
                $this->refusedThreads = [$argument, $threads];

                throw new InvalidLegacyArgument($argument);
            }
            $input['--' . $option] = $value;
            if (!self::accepted($flag, $value)) {
                break;
            }
            if ($flag === '--threads') {
                $threads = self::threads($value);
            }
        }

        return [$input, null];
    }

    /**
     * The script's --threads check: a whole number of at least 1 after trimming.
     */
    public static function threads(string $value): ?int
    {
        $threads = filter_var(trim($value), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        return $threads === false ? null : $threads;
    }

    /** Whether the script accepted the value of a flag, or stopped there. */
    private static function accepted(string $flag, string $value): bool
    {
        $trimmed = trim($value);

        return match ($flag) {
            '--host-id' => ctype_digit($trimmed) && (int) $trimmed >= 1,
            '--host-template-id', '--data-template-id' => is_numeric($trimmed),
            '--threads' => self::threads($value) !== null,
            default => true,
        };
    }

    /**
     * The threads error for a --threads that followed a valid one, otherwise
     * the error and help for an unknown argument.
     *
     * @return list<string>
     */
    #[\Override]
    public function invalidOutput(string $argument): array
    {
        if ($this->refusedThreads !== null && $this->refusedThreads[0] === $argument) {
            return [self::badThreads((string) $this->refusedThreads[1])];
        }

        return parent::invalidOutput($argument);
    }

    /**
     * display_help() after its version line, word for word.
     *
     * @return list<string>
     */
    #[\Override]
    public function help(): array
    {
        return [
            '',
            'usage: rebuild_poller_cache.php [--host-id=N] [--host-template-id=N] [--data-template-id=N] [--debug]',
            '',
            'Kadupul\'s repopulate poller cache tool.  This CLI script will ',
            'repopulate poller cache for all or specified hosts.',
            '',
            'This utility will run in parallel with the given number of threads,',
            'Optional:',
            ' --threads=N           - The number of threads to use to repopulate, default = Poller Command Concurrent Processes setting',
            ' --host-id=N           - Run for a specific Device',
            ' --host-template-id=N  - Run for a specific Device Template',
            ' --data-template-id=N  - Run for a specific Data Template',
            ' --debug               - Display verbose output during execution',
            '',
            'System Controlled:',
            ' --type      - The type and subtype of the rebuild poller cache process',
            ' --child     - The thread id of the child process',
            '',
        ];
    }

    /** The script printed this one to stderr. */
    public static function badHostId(): string
    {
        return 'ERROR: --host-id must be a positive integer.';
    }

    /** The script printed this one, and the next two, to stdout. */
    public static function badHostTemplateId(): string
    {
        return 'ERROR: You must supply a valid Device Template Id to run this script!';
    }

    /** The --data-template-id error. */
    public static function badDataTemplateId(): string
    {
        return 'ERROR: You must supply a valid Data Template Id to run this script!';
    }

    /**
     * @param string $default the value --threads would otherwise have had, quoted as the script printed it
     */
    public static function badThreads(string $default): string
    {
        return 'ERROR: You must supply a valid Number of Treads or skip this parameter for default value (' . $default . ')';
    }
}
