<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Infrastructure\Symfony\Console;

use Kadupul\Platform\Application\ReadModel\WideningEvent;
use Kadupul\Platform\Application\ReadModel\WideningReport;

final class WidenIdColumnsLegacyArguments extends LegacyArguments
{
    #[\Override]
    protected function flags(): array
    {
        // --as is not in the original script; the shim accepts it so operators
        // can name an account without moving to bin/console first.
        return [
            '-d' => ['debug', false], '--debug' => ['debug', false], '--local' => ['local', false],
            '--as' => ['as', true],
            ...self::versionAndHelp(),
        ];
    }

    /** The original's help text after its version line, which the caller prepends. */
    #[\Override]
    public function help(): array
    {
        return ['usage: fix_mediumint.php [--debug]', '',
            'Options:',
            '--debug    - Display verbose output during execution',
            '--local    - Perform the action on the Remote Data Collector if run from there', '',
            'This utility is used to increase the size of key Kadupul columns to accomodate',
            'systems with over a million graphs and that have been in service for years.',
            'After some long amount of time, Kadupul can run out of auto_increment fields.'];
    }

    /** @return list<string> */
    public function report(WideningReport $report, bool $debug): array
    {
        $lines = ['NOTE: Fixing MediumInt Columns for ' . ($report->main ? 'Main' : 'Local') . ' Database'];
        if ($debug) {
            foreach ($report->steps as $step) {
                $lines[] = 'DEBUG: ' . match ($step['event']) {
                    WideningEvent::AlreadyWide => 'Column ' . $step['column'] . ' in Table ' . $step['table'] . ' already converted.',
                    WideningEvent::Skipped => 'Column ' . $step['column'] . ' in Table ' . $step['table'] . ' is generated or invisible, skipped.',
                    WideningEvent::MissingColumn => 'ERROR: Attributes missing for ' . $step['table'] . ' and column ' . $step['column'] . '.',
                    WideningEvent::Planned, WideningEvent::Widened, WideningEvent::Failed => 'Updating Table ' . $step['table'] . '.',
                };
            }
        }
        $lines[] = 'NOTE: Column widths adjusted on ' . $report->tables() . ' Tables!';

        return $lines;
    }
}
