<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Infrastructure\Symfony\Console;

/**
 * The old scripts split "--flag=value" on the first "=" and matched the flag
 * exactly. Anything they did not match is an error here too, never dropped.
 */
abstract class LegacyArguments
{
    private const array VERSION = ['--version', '-V', '-v'];

    /**
     * @return array<string, array{0: ?string, 1: bool, 2?: string}> old flag =>
     *     [new option or null for a special mode, takes a value, optional PCRE the value must match].
     *     The pattern is checked only for a flag that takes a value.
     */
    abstract protected function flags(): array;

    /** @return list<string> */
    abstract public function help(): array;

    /**
     * The first lines the original printed for an unknown argument, before its help.
     *
     * @return list<string>
     */
    public function invalid(string $argument): array
    {
        return ['ERROR: Invalid Parameter ' . $argument, ''];
    }

    /**
     * Whether the original printed its version line above the help for --help
     * and for a run without enough arguments. Most did; repair_graphs.php did not.
     */
    public function helpPrintsVersion(): bool
    {
        return true;
    }

    /**
     * Everything the original printed for an unknown argument: the lines of
     * invalid(), then its help. A script that stopped at the error without the
     * help overrides this.
     *
     * @return list<string>
     */
    public function invalidOutput(string $argument): array
    {
        return [...$this->invalid($argument), ...$this->help()];
    }

    /**
     * The command that runs the translated input. A script whose old flags
     * select between several commands, such as a --list-* mode, overrides this;
     * the others run the one command their shim names.
     *
     * @param array<string, mixed> $input the result of translate()
     */
    public function route(array $input, string $default): string
    {
        return $default;
    }

    /**
     * Name of the special mode a null-mapped flag selects: version for the
     * versionAndHelp() version flags, help for every other.
     */
    protected function special(string $flag): LegacyRequest
    {
        return in_array($flag, self::VERSION, true) ? LegacyRequest::Version : LegacyRequest::Help;
    }

    /**
     * The version and help flags the cli/ scripts shared, for a flags() map.
     *
     * @return array<string, array{0: null, 1: false}>
     */
    protected static function versionAndHelp(): array
    {
        return array_fill_keys([...self::VERSION, '--help', '-H', '-h'], [null, false]);
    }

    /**
     * @param list<string> $argv arguments after the script name
     * @return array{0: array<string, mixed>, 1: ?LegacyRequest}
     */
    public function translate(array $argv): array
    {
        $flags = $this->flags();
        $input = [];
        foreach ($argv as $argument) {
            [$flag, $value] = str_contains($argument, '=') ? explode('=', $argument, 2) : [$argument, null];
            if (!array_key_exists($flag, $flags)) {
                throw new InvalidLegacyArgument($argument);
            }
            [$option, $takesValue] = $flags[$flag];
            if ($option === null) {
                return [[], $this->special($flag)];
            }
            if ($takesValue && ($value === null || $value === '')) {
                throw new InvalidLegacyArgument($argument);
            }
            // A value the command would reject anyway is refused here, before the
            // kernel boots, so the error names the flag as the operator typed it.
            if ($takesValue && isset($flags[$flag][2]) && preg_match($flags[$flag][2], (string) $value) !== 1) {
                throw new InvalidLegacyArgument($argument);
            }
            $input['--' . $option] = $takesValue ? $value : true;
        }

        return [$input, null];
    }
}
