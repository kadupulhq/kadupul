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
    /**
     * @return array<string, array{0: ?string, 1: bool, 2?: string}> old flag =>
     *     [new option or null for a special mode, takes a value, optional PCRE the value must match].
     *     The pattern is checked only for a flag that takes a value.
     */
    abstract protected function flags(): array;

    /** @return list<string> */
    abstract public function help(): array;

    /** @return list<string> */
    abstract public function invalid(string $argument): array;

    /** Name of the special mode a null-mapped flag selects. */
    protected function special(string $flag): LegacyRequest
    {
        return LegacyRequest::Help;
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
