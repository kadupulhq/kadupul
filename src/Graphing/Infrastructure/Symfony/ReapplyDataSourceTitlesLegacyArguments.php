<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Graphing\Infrastructure\Symfony;

use Kadupul\Graphing\Application\Port\DataSourceTitle;
use Kadupul\Platform\Infrastructure\Symfony\Console\InvalidLegacyArgument;
use Kadupul\Platform\Infrastructure\Symfony\Console\LegacyArguments;
use Kadupul\Platform\Infrastructure\Symfony\Console\LegacyRequest;

/**
 * poller_data_sources_reapply_names.php accepted a flag with no value or an empty one
 * and rejected it later with its own message, so translate() passes empty
 * values through instead of refusing them as the base map does.
 */
final class ReapplyDataSourceTitlesLegacyArguments extends LegacyArguments
{
    #[\Override]
    protected function flags(): array
    {
        return [
            '-id' => ['host-id', true],
            '--id' => ['host-id', true],
            '--host-id' => ['host-id', true],
            '-s' => ['filter', true],
            '--filter' => ['filter', true],
            '--debug' => ['debug', false],
            '-d' => ['debug', false],
            ...self::versionAndHelp(),
        ];
    }

    #[\Override]
    public function translate(array $argv): array
    {
        $flags = $this->flags();
        $input = [];
        foreach ($argv as $argument) {
            // strpos() in the original: a leading "=" was no separator.
            [$flag, $value] = strpos($argument, '=') > 0 ? explode('=', $argument, 2) : [$argument, ''];
            if (!array_key_exists($flag, $flags)) {
                throw new InvalidLegacyArgument($argument);
            }
            [$option, $takesValue] = $flags[$flag];
            if ($option === null) {
                return [[], $this->special($flag)];
            }
            $input['--' . $option] = $takesValue ? $value : true;
        }

        return [$input, null];
    }

    #[\Override]
    public function help(): array
    {
        return ['', 'usage: poller_data_sources_reapply_names.php --host-id=[id|all][N1,N2,...] [--filter=string] [--debug]', '',
            'A utility that will recalculate Data Source names for the selected Data Templates.', '',
            'Required:',
            "    --host-id=N|all|N1,N2,... - The devices id, 'all' or a comma delimited list of id's", '',
            'Optional:',
            '    --filter=search           - A Data Template name or Data Source Title to search for',
            '    --debug                   - Display verbose output during execution', ''];
    }

    /** @return list<string> */
    public function noInput(): array
    {
        return ['ERROR: You must supply input parameters', ''];
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
