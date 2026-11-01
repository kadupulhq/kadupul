<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Graphing\Infrastructure\Symfony;

use Kadupul\Graphing\Application\Command\DataQueryAssociation;
use Kadupul\Graphing\Application\Port\CatalogChoice;
use Kadupul\Graphing\Application\Port\CatalogDevice;
use Kadupul\Graphing\Domain\ReindexMethod;
use Kadupul\Platform\Infrastructure\Symfony\Console\InvalidLegacyArgument;
use Kadupul\Platform\Infrastructure\Symfony\Console\LegacyArguments;
use Kadupul\Platform\Infrastructure\Symfony\Console\LegacyRequest;

/**
 * add_data_query.php split each argument at the first "=" that was not its
 * first character, kept a value only for its three value flags, and stopped at
 * the first value that was not valid. A flag it knew took any "=value" and
 * ignored it. --quiet is the console's own option, so it reaches the command as
 * --no-headers.
 */
final class AssociateDataQueryLegacyArguments extends LegacyArguments
{
    #[\Override]
    protected function flags(): array
    {
        return ['-d' => [null, false], '--host-id' => ['host-id', true], '--data-query-id' => ['data-query-id', true], '--reindex-method' => ['reindex-method', true],
            '--list-hosts' => ['list-hosts', false], '--list-data-queries' => ['list-data-queries', false], '--quiet' => ['no-headers', false],
            ...self::versionAndHelp()];
    }

    #[\Override]
    public function translate(array $argv): array
    {
        if ($argv === []) {
            return [[], LegacyRequest::Help];
        }
        $flags = $this->flags();
        $input = [];
        foreach ($argv as $argument) {
            [$flag, $value] = strpos($argument, '=') > 0 ? explode('=', $argument, 2) : [$argument, ''];
            if (!array_key_exists($flag, $flags)) {
                throw new InvalidLegacyArgument($argument);
            }
            if ($flag === '-d') {
                continue;
            }
            [$option, $takesValue] = $flags[$flag];
            if ($option === null) {
                return [[], $this->special($flag)];
            }
            $input['--' . $option] = $takesValue ? $value : true;
            // The original exited at the first value that was not valid, so nothing after it counts.
            if ($flag === '--host-id' && !is_numeric(trim($value)) || $flag === '--data-query-id' && !is_numeric($value)
                || $flag === '--reindex-method' && ReindexMethod::parse($value) === null) {
                break;
            }
        }

        return [$input, null];
    }

    #[\Override]
    public function invalid(string $argument): array
    {
        return ['ERROR: Invalid Argument: (' . (strpos($argument, '=') > 0 ? explode('=', $argument, 2)[0] : $argument) . ')', ''];
    }

    #[\Override]
    public function help(): array
    {
        return ['', 'usage: add_data_query.php --host-id=[ID] --data-query-id=[dq_id] --reindex-method=[method] [--quiet]', '',
            'Required Options:',
            '    --host-id         the numerical ID of the host',
            '    --data-query-id   the numerical ID of the data_query to be added',
            '    --reindex-method  the reindex method to be used for that data query',
            '                      0|None   = no reindexing',
            '                      1|Uptime = Uptime goes Backwards',
            '                      2|Index  = Index Count Changed',
            '                      3|Fields = Verify all Fields', '',
            'List Options:',
            '    --list-hosts',
            '    --list-data-queries',
            '    --quiet - batch mode value return', '',
            'If the data query was already associated, it will be reindexed.', ''];
    }

    /** @return list<string> */
    public function badHostId(): array
    {
        return ['ERROR: You must supply a valid host-id to run this script!'];
    }

    /** @return list<string> */
    public function badDataQueryId(): array
    {
        return ['ERROR: You must supply a numeric data-query-id for all hosts!'];
    }

    /** @return list<string> */
    public function badReindexMethod(): array
    {
        return ['ERROR: You must supply a valid reindex method for all hosts!'];
    }

    /** @return list<string> */
    public function missingHostId(): array
    {
        return ['ERROR: You must supply a valid host-id for all hosts!'];
    }

    /** @return list<string> */
    public function missingDataQueryId(): array
    {
        return ['ERROR: You must supply a valid data-query-id for all hosts!'];
    }

    /** @return list<string> */
    public function missingReindexMethod(): array
    {
        return ['ERROR: You must supply a valid reindex-method for all hosts!'];
    }

    /**
     * @param list<CatalogDevice> $devices
     * @return list<string>
     */
    public function devices(array $devices, bool $quiet): array
    {
        return [...$quiet ? [] : ['Known Devices: (id, hostname, template, description)'],
            ...array_map(static fn(CatalogDevice $device): string => $device->id . "\t" . $device->hostname . "\t" . $device->hostTemplateId . "\t" . $device->description, $devices),
            ...$quiet ? [] : ['']];
    }

    /**
     * @param list<CatalogChoice> $queries
     * @return list<string>
     */
    public function dataQueries(array $queries, bool $quiet): array
    {
        return [...$quiet ? [] : ['Known SNMP Queries: (id, name)'],
            ...array_map(static fn(CatalogChoice $query): string => $query->id . "\t" . $query->name, $queries),
            ...$quiet ? [] : ['']];
    }

    /**
     * The outcome as the original printed it, with the ids as the operator typed them.
     *
     * @return list<string>
     */
    public function outcome(DataQueryAssociation $association, string $hostId, string $dataQueryId, ReindexMethod $method): array
    {
        $tail = ' (' . $hostId . ': ' . ($association->device->hostname ?? '') . ') data query (' . $dataQueryId . ': ' . ($association->dataQuery->name ?? '') . ') reindex method (' . $method->printed() . ': ' . $method->name() . ')';

        return match ($association->status) {
            DataQueryAssociation::UNKNOWN_DEVICE => ["ERROR: Unknown Host Id ($hostId)"],
            DataQueryAssociation::UNKNOWN_QUERY => ["ERROR: Unknown Data Query Id ($dataQueryId)"],
            DataQueryAssociation::ALREADY => ['ERROR: Data Query is already associated for host:' . $tail],
            DataQueryAssociation::FAILED => ['ERROR: Failed to add this data query for host' . $tail],
            default => ['Success - Host' . $tail],
        };
    }
}
