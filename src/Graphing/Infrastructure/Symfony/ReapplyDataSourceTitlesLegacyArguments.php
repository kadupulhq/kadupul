<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Graphing\Infrastructure\Symfony;

use Kadupul\Graphing\Application\Port\DataSourceTitle;

/** The arguments of poller_data_sources_reapply_names.php; see HostSelectionLegacyArguments. */
final class ReapplyDataSourceTitlesLegacyArguments extends HostSelectionLegacyArguments
{
    #[\Override]
    protected function selectionFlags(): array
    {
        return [
            '--id' => ['host-id', true],
            '-s' => ['filter', true],
            '--filter' => ['filter', true],
        ];
    }

    #[\Override]
    protected function usage(): string
    {
        return 'usage: poller_data_sources_reapply_names.php --host-id=[id|all][N1,N2,...] [--filter=string] [--debug]';
    }

    #[\Override]
    protected function description(): string
    {
        return 'A utility that will recalculate Data Source names for the selected Data Templates.';
    }

    #[\Override]
    protected function required(): array
    {
        return ["    --host-id=N|all|N1,N2,... - The devices id, 'all' or a comma delimited list of id's"];
    }

    #[\Override]
    protected function optional(): array
    {
        return [
            '    --filter=search           - A Data Template name or Data Source Title to search for',
            '    --debug                   - Display verbose output during execution',
        ];
    }

    /**
     * What the original printed for a run: with nothing selected only a warning
     * and no newline; otherwise a warning, then a dot for each data source, or
     * with --debug a line before and after each.
     *
     * @param list<DataSourceTitle> $dataSources
     * @param list<string> $renamed the title each finished data source ended with
     * @return array{0: list<string>, 1: bool} the lines, and whether the last ends in a newline
     */
    public function run(array $dataSources, array $renamed, bool $debug): array
    {
        if ($dataSources === []) {
            return [['WARNING: No Data Sources where found matching the selected criteria.'], false];
        }
        $lines = ['WARNING: Do not interrupt this script.  Interrupting during rename can cause issues'];
        if ($debug) {
            $lines[] = "DEBUG: There are '" . count($dataSources) . "' Data Sources to rename";
            foreach ($dataSources as $index => $source) {
                $lines[] = "DEBUG: Data Source Name '" . $source->title . "' starting";
                if (isset($renamed[$index])) {
                    $lines[] = "DEBUG: Data Source Rename Done for Data Source '" . addslashes($renamed[$index]) . "'";
                }
            }

            return [$lines, true];
        }

        return [[...$lines, str_repeat('.', count($dataSources))], false];
    }
}
