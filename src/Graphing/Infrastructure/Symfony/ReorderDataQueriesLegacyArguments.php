<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Graphing\Infrastructure\Symfony;

use Kadupul\Graphing\Application\Port\DataQueryIndexItem;

/** The arguments of reorder_data_query.php; see HostSelectionLegacyArguments. */
final class ReorderDataQueriesLegacyArguments extends HostSelectionLegacyArguments
{
    #[\Override]
    protected function selectionFlags(): array
    {
        return [
            '--id' => ['host-id', true],
            '-qid' => ['qid', true],
            '--qid' => ['qid', true],
        ];
    }

    #[\Override]
    protected function usage(): string
    {
        return 'usage: reorder_data_query.php --host-id=[id|all] --qid=[query_id|all] [--debug|-d]';
    }

    #[\Override]
    protected function description(): string
    {
        return 'A utility to Re-order Kadupul Data Queries for a Device or system in batch mode.';
    }

    #[\Override]
    protected function required(): array
    {
        return [
            "    --host-id=N|all - The Device id to be reindexed, or 'all' for every Device.",
            "    --qid=query_id|all - The query id to reindex, or 'all' for every query.",
        ];
    }

    #[\Override]
    protected function optional(): array
    {
        return [
            '    --debug | -d   - Display verbose output during execution',
        ];
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
