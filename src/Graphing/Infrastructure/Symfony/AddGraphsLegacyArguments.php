<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Graphing\Infrastructure\Symfony;

use Kadupul\Graphing\Application\Port\CatalogChoice;
use Kadupul\Graphing\Application\Port\CatalogDevice;
use Kadupul\Graphing\Application\Port\InputField;
use Kadupul\Platform\Infrastructure\Symfony\Console\InvalidLegacyArgument;
use Kadupul\Platform\Infrastructure\Symfony\Console\LegacyArguments;
use Kadupul\Platform\Infrastructure\Symfony\Console\LegacyRequest;

/**
 * add_graphs.php read its options with getopt(): a value only after "=", an
 * option written as "--name=" with nothing after the "=" was not seen at all, and
 * a value-taking option with none had an empty value that the script refused
 * later with its own message. Only --snmp-field, --snmp-value and
 * --snmp-value-regex could be given more than once; the script refused a second
 * value for any other option. --quiet is the console's own option, so it reaches
 * the command as --no-headers.
 */
final class AddGraphsLegacyArguments extends LegacyArguments
{
    /** Old flags that may be repeated. */
    private const array LISTS = ['--snmp-field', '--snmp-value', '--snmp-value-regex'];

    #[\Override]
    protected function flags(): array
    {
        $flags = [];
        foreach (['--host-id', '--graph-type', '--graph-template-id', '--graph-title', '--host-template-id', '--input-fields', '--snmp-query-id', '--snmp-query-type-id',
            '--snmp-field', '--snmp-value', '--snmp-value-regex', '--reindex-method'] as $flag) {
            $flags[$flag] = [substr($flag, 2), true];
        }
        foreach (['--list-hosts', '--list-snmp-fields', '--list-snmp-values', '--list-query-types', '--list-snmp-queries', '--force', '--list-input-fields', '--list-graph-templates'] as $flag) {
            $flags[$flag] = [substr($flag, 2), false];
        }
        $flags['--quiet'] = ['no-headers', false];

        return [...$flags, ...self::versionAndHelp()];
    }

    #[\Override]
    public function translate(array $argv): array
    {
        if ($argv === []) {
            return [[], LegacyRequest::Usage];
        }
        $flags = $this->flags();
        $values = [];
        $lists = [];
        $seen = [];
        foreach ($argv as $argument) {
            [$flag, $value] = str_contains($argument, '=') ? explode('=', $argument, 2) : [$argument, ''];
            if (!array_key_exists($flag, $flags)) {
                throw new InvalidLegacyArgument($argument);
            }
            [$option, $takesValue] = $flags[$flag];
            if ($option === null) {
                return [[], $this->special($flag)];
            }
            if ($takesValue && str_contains($argument, '=') && $value === '') {
                continue;
            }
            $seen[$flag] = ($seen[$flag] ?? 0) + 1;
            if (in_array($flag, self::LISTS, true)) {
                $lists[$option][] = $value;
            } elseif ($seen[$flag] === 1) {
                $values[$option] = $takesValue ? $value : true;
            }
        }
        $input = [];
        foreach ([...$values, ...$lists] as $option => $value) {
            $input['--' . $option] = $value;
        }
        // In the order getopt() reported them: where each option first appeared.
        $repeated = [];
        foreach ($seen as $flag => $count) {
            if ($count > 1 && !in_array($flag, self::LISTS, true)) {
                $repeated[] = $flag === '--quiet' ? 'quiet' : substr($flag, 2);
            }
        }
        if ($repeated !== []) {
            $input['--repeated'] = $repeated;
        }

        return [$input, null];
    }

    #[\Override]
    public function invalid(string $argument): array
    {
        return ['ERROR: Invalid Argument: (' . ltrim(explode('=', $argument, 2)[0], '-') . ')', ''];
    }

    #[\Override]
    public function help(): array
    {
        return ['',
            'usage: add_graphs.php --graph-type=[cg|ds] --graph-template-id=[ID]',
            '    --host-id=[ID] [--graph-title=title] [graph options] [--force] [--quiet]',
            '',
            'Kadupul utility for creating graphs via a command line interface.  This utility can',
            'create both Data Query (ds) type Graphs as well as Graph Template (cg) type graphs.',
            '',
            'For Non Data Query (cg) Graphs:',
            '    [--input-fields="[data-template-id:]field-name=value ..."] [--force]',
            '',
            '    --input-fields  If your data template allows for custom input data, you may specify that',
            '                    here.  The data template id is optional and applies where two input fields',
            '                    have the same name.',
            '    --force         If you set this flag, then new cg graphs will be created, even though they',
            '                    may already exist',
            '',
            'For Data Query (ds) Graphs:',
            '    --snmp-query-id=[ID] --snmp-query-type-id=[ID] --snmp-field=[SNMP Field] ',
            '                         --snmp-value=[SNMP Value] | --snmp-value-regex=[REGEX]',
            '    [--graph-title=S]       Defaults to what ever is in the Graph Template/Data Template.',
            '    [--reindex-method=N]    The reindex method to be used for that data query.',
            '                            NOTE: If Data Query is already associated, the reindex method will NOT be changed.',
            '',
            '    Valid --reindex-methods include',
            '        0|None   = No reindexing',
            '        1|Uptime = Uptime goes Backwards (Default)',
            '        2|Index  = Index Count Changed',
            '        3|Fields = Verify all Fields',
            '',
            '    NOTE: You may supply multiples of the --snmp-field and --snmp-value | --snmp-value-regex arguments.',
            '',
            'List Options:',
            '    --list-hosts',
            '    --list-graph-templates [--host-template-id=[ID]]',
            '    --list-input-fields --graph-template-id=[ID]',
            '    --list-snmp-queries',
            '    --list-query-types  --snmp-query-id [ID]',
            '    --list-snmp-fields  --host-id=[ID] [--snmp-query-id=[ID]]',
            '    --list-snmp-values  --host-id=[ID] [--snmp-query-id=[ID]] --snmp-field=[Field]',
            ''];
    }

    /**
     * @param list<CatalogChoice> $templates
     * @return list<string>
     */
    public function templates(array $templates, bool $quiet): array
    {
        return $this->list('Known Graph Templates: (id, name)', array_map(static fn(CatalogChoice $template): string => $template->id . "\t" . $template->name, $templates), $quiet);
    }

    /**
     * @param list<InputField> $fields
     * @return list<string>
     */
    public function inputFields(array $fields, bool $quiet): array
    {
        return $this->list('Known Input Fields: (name, default, description)', array_map(static fn(InputField $field): string => $field->key() . "\t" . $field->default . "\t" . $field->description, $fields), $quiet);
    }

    /**
     * @param list<CatalogDevice> $devices
     * @return list<string>
     */
    public function devices(array $devices, bool $quiet): array
    {
        return $this->list('Known Devices: (id, hostname, template, description)', array_map(static fn(CatalogDevice $device): string => $device->id . "\t" . $device->hostname . "\t" . $device->hostTemplateId . "\t" . $device->description, $devices), $quiet);
    }

    /**
     * @param list<CatalogChoice> $queries
     * @return list<string>
     */
    public function queries(array $queries, bool $quiet): array
    {
        return $this->list('Known SNMP Queries: (id, name)', array_map(static fn(CatalogChoice $query): string => $query->id . "\t" . $query->name, $queries), $quiet);
    }

    /**
     * @param list<CatalogChoice> $types
     * @return list<string>
     */
    public function queryTypes(array $types, bool $quiet): array
    {
        return $this->list('Known SNMP Query Types: (id, name)', array_map(static fn(CatalogChoice $type): string => $type->id . "\t" . $type->name, $types), $quiet);
    }

    /**
     * @param list<string> $fields
     * @return list<string>
     */
    public function fields(array $fields, string $deviceId, bool $quiet): array
    {
        return $this->list('Known SNMP Fields for host-id ' . $deviceId . ': (name)', $fields, $quiet);
    }

    /**
     * The original printed the values of the last field once for each field given, under an empty field name.
     *
     * @param list<string> $values
     * @return list<string>
     */
    public function values(array $values, string $deviceId, int $repeats, bool $quiet): array
    {
        $lines = [];
        for ($time = 0; $time < $repeats; $time++) {
            array_push($lines, ...$this->list('Known SNMP Values for Field  and host-id ' . $deviceId . ': (name)', $values, $quiet));
        }

        return $lines;
    }

    /**
     * @param list<string> $rows
     * @return list<string>
     */
    private function list(string $heading, array $rows, bool $quiet): array
    {
        return [...$quiet ? [] : [$heading], ...$rows, ...$quiet ? [] : ['']];
    }
}
