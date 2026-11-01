<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Graphing\Infrastructure\Symfony;

use Kadupul\Graphing\Application\Port\DataQueryIndexItem;
use Kadupul\Platform\Infrastructure\Symfony\Console\InvalidLegacyArgument;
use Kadupul\Platform\Infrastructure\Symfony\Console\LegacyArguments;
use Kadupul\Platform\Infrastructure\Symfony\Console\LegacyRequest;

/**
 * reorder_data_query.php accepted a flag with no value or an empty one and
 * rejected it later with its own message, so translate() passes empty values
 * through instead of refusing them as the base map does.
 */
final class ReorderDataQueriesLegacyArguments extends LegacyArguments
{
    #[\Override]
    protected function flags(): array
    {
        return [
            '-id' => ['host-id', true],
            '--id' => ['host-id', true],
            '--host-id' => ['host-id', true],
            '-qid' => ['qid', true],
            '--qid' => ['qid', true],
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
        return ['', 'usage: reorder_data_query.php --host-id=[id|all] --qid=[query_id|all] [--debug|-d]', '',
            'A utility to Re-order Kadupul Data Queries for a Device or system in batch mode.', '',
            'Required:',
            "    --host-id=N|all - The Device id to be reindexed, or 'all' for every Device.",
            "    --qid=query_id|all - The query id to reindex, or 'all' for every query.", '',
            'Optional:',
            '    --debug | -d   - Display verbose output during execution', ''];
    }

    /** @return list<string> */
    public function noInput(): array
    {
        return ['ERROR: You must supply input parameters', ''];
    }

    /** What the script wrote to stderr, and stopped with, for an item it could not index. */
    public function unresolved(DataQueryIndexItem $item): string
    {
        return 'ERROR: Unable to determine sort field for Device[' . $item->hostId . '] and DQ[' . $item->snmpQueryId . '].';
    }

    /**
     * What the original printed for a run: a warning, then a dot for each item it
     * started, or with --debug a line for the count and one for each item it
     * indexed. A run that stopped at an item printed that item's dot first.
     *
     * @param list<DataQueryIndexItem> $items
     * @param list<string> $indexOn the field each finished item was indexed on
     * @return array{0: list<string>, 1: bool} the lines, and whether the last ends in a newline
     */
    public function run(array $items, array $indexOn, bool $unresolved, bool $debug): array
    {
        $lines = ['WARNING: Do not interrupt this script.  Reordering can take quite some time'];
        if ($debug) {
            $lines[] = "DEBUG: There are '" . count($items) . "' data query index items to run";
            foreach ($indexOn as $position => $field) {
                $item = $items[$position];
                // debug() trimmed its message.
                $lines[] = trim("DEBUG: Data Query #'" . ($position + 1) . "' host: '" . $item->hostId
                    . "' SNMP Query Id: '" . $item->snmpQueryId . "' Index: " . $item->snmpIndex . "' Index On: " . $field);
            }

            return [$lines, true];
        }
        $started = count($indexOn) + ($unresolved ? 1 : 0);
        if ($started === 0) {
            return [$lines, true];
        }

        return [[...$lines, str_repeat('.', $started)], false];
    }
}
