<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Graphing\Infrastructure\Symfony;

use Kadupul\Graphing\Application\Command\GraphRepairEntry;
use Kadupul\Platform\Infrastructure\Symfony\Console\InvalidLegacyArgument;
use Kadupul\Platform\Infrastructure\Symfony\Console\LegacyArguments;

/**
 * repair_graphs.php checked each id as it read it and stopped with one line
 * and no help, so translate() is its own and invalidOutput() prints that line
 * for an id and the help for an unknown flag.
 */
final class RepairGraphItemsLegacyArguments extends LegacyArguments
{
    private const array IDS = [
        '--host-id' => ['host-id', 'ERROR: You must supply a valid host-id to run this script!'],
        '--graph-template-id' => ['graph-template-id', 'ERROR: You must supply a numeric graph-template-id!'],
        '--data-template-id' => ['data-template-id', 'ERROR: You must supply a numeric data-template-id!'],
    ];

    #[\Override]
    protected function flags(): array
    {
        return [
            '--execute' => ['execute', false],
            '--show-sql' => ['show-sql', false],
            '--host-id' => ['host-id', true],
            '--graph-template-id' => ['graph-template-id', true],
            '--data-template-id' => ['data-template-id', true],
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
            if (!$takesValue) {
                $input['--' . $option] = true;

                continue;
            }
            // is_numeric() in the original also admitted decimals, exponents and signs.
            if (preg_match('/^\d{1,18}$/D', trim($value)) !== 1) {
                throw new InvalidLegacyArgument($argument);
            }
            $input['--' . $option] = trim($value);
        }

        return [$input, null];
    }

    #[\Override]
    public function invalidOutput(string $argument): array
    {
        $flag = strpos($argument, '=') > 0 ? explode('=', $argument, 2)[0] : $argument;
        if (isset(self::IDS[$flag])) {
            return [self::IDS[$flag][1]];
        }

        return parent::invalidOutput($argument);
    }

    #[\Override]
    public function helpPrintsVersion(): bool
    {
        return false;
    }

    #[\Override]
    public function help(): array
    {
        return ['usage: repair_graphs.php [--host-id=ID] --data-template-id=[ID]',
            "\t--graph-template-id=[ID] [--show-sql] [--execute]", '',
            'Kadupul utility for repairing graph<->datasource relationship via a command line interface.', '',
            '--execute - Perform the repair',
            '--show-sql - Show SQL lines for the repair (optional)',
            '--host-id=id - The host_id to repair or leave empty to process all hosts',
            '--data-template-id=id - The numerical ID of the data template to be fixed',
            '--graph-template-id=id - The numerical ID of the graph template to be fixed'];
    }

    /**
     * What the original printed, in order: the mode, then for each graph a line
     * naming what it would change and, with --show-sql, the statement.
     *
     * @param list<GraphRepairEntry> $entries
     * @return list<string>
     */
    public function run(bool $execute, bool $showSql, int $checked, array $entries): array
    {
        $lines = [$execute ? 'NOTE: Repairing Graphs' : 'NOTE: Performing Check of Graphs'];
        if ($checked > 0 && !$showSql) {
            array_push($lines, '', 'Corrupted graphs:');
        }
        foreach ($entries as $entry) {
            if ($entry->isMissingRrd()) {
                $lines[] = 'Could not get correct rrd id for datasource=' . $entry->dataSourceId;

                continue;
            }
            $lines[] = 'Host ' . $entry->hostId . ', graph ' . $entry->graphId . ', graph item ' . implode(',', $entry->itemIds) . ', task_item_id ' . implode(',', $entry->oldTaskItemIds) . '->' . $entry->rrdId;
            if ($showSql) {
                $lines[] = $entry->sql . ';';
            }
        }

        return $lines;
    }
}
