<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Infrastructure\Symfony\Console;

final class AnalyzeDatabaseLegacyArguments extends LegacyArguments
{
    #[\Override]
    protected function flags(): array
    {
        // --as is not in the original script; the shim accepts it so operators
        // can name an account without moving to bin/console first.
        return ['-d' => ['debug', false], '--debug' => ['debug', false], '--local' => ['local', false], '--as' => ['as', true],
            '--version' => [null, false], '-V' => [null, false], '-v' => [null, false],
            '--help' => [null, false], '-H' => [null, false], '-h' => [null, false]];
    }

    #[\Override]
    protected function special(string $flag): LegacyRequest
    {
        return in_array($flag, ['--version', '-V', '-v'], true) ? LegacyRequest::Version : LegacyRequest::Help;
    }

    /** The original's help text after its version line, which the command prepends. */
    #[\Override]
    public function help(): array
    {
        return ['', 'usage: analyze_database.php [-d|--debug]', '',
            'A utility to recalculate the cardinality of indexes within the Kadupul database.',
            "It's important to periodically run this utility especially on larger systems.", '',
            'Optional:',
            '     --local   - Perform the action on the Remote Data Collector if run from there',
            '-d | --debug   - Display verbose output during execution', ''];
    }

    #[\Override]
    public function invalid(string $argument): array
    {
        return ['ERROR: Invalid Parameter ' . $argument, ''];
    }
}
