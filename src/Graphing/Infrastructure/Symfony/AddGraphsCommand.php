<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Graphing\Infrastructure\Symfony;

use Kadupul\Graphing\Application\Command\AddGraphs;
use Kadupul\Graphing\Application\Command\GraphAddition;
use Kadupul\Graphing\Application\Command\GraphManagementDenied;
use Kadupul\Graphing\Application\Command\GraphRequest;
use Kadupul\Graphing\Application\Command\GraphResult;
use Kadupul\IdentityAccess\Contract\OperatorDatabase;
use Kadupul\Platform\Infrastructure\Legacy\InstallationVersion;
use Kadupul\Platform\Infrastructure\Symfony\Console\CliPresentation;
use Kadupul\Platform\Infrastructure\Symfony\Console\CommandResult;
use Kadupul\Platform\Infrastructure\Symfony\Console\OutputMode;
use Kadupul\Platform\Infrastructure\Symfony\Console\ResultRenderer;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\MapInput;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'kadupul:graph:add', description: 'Create graphs from a graph template or from the indexes of a data query.')]
final readonly class AddGraphsCommand
{
    private const string UTILITY = 'Kadupul Add Graphs Utility';

    public function __construct(
        private AddGraphs $addGraphs,
        private InstallationVersion $version,
        private CliPresentation $presentation,
        private ResultRenderer $renderer,
    ) {}

    public function __invoke(SymfonyStyle $io, OutputInterface $output, #[MapInput] AddGraphsInput $input): int
    {
        $mode = $input->json ? OutputMode::Json : $this->presentation->mode;
        $legacy = new AddGraphsLegacyArguments();
        $early = $this->renderer->preflight($this->presentation->legacy, $this->version, self::UTILITY, $legacy, $input->as, $io, $output, $mode);
        if ($early !== null) {
            return $early;
        }
        if ($input->repeated !== []) {
            return $this->refuse($io, $output, $mode, 'invalid', 'The ' . $input->repeated[0] . ' option takes one value.', ['ERROR: Multiple values specified for non-multi argument: (' . $input->repeated[0] . ')', ''], [], true);
        }
        $reindex = $this->reindex($input->reindexMethod);
        if ($reindex === null) {
            return $this->refuse($io, $output, $mode, 'invalid', 'The --reindex-method option must be 0-3, none, uptime, index or fields.', ['ERROR: You must supply a valid reindex method for this graph!'], [], true);
        }
        $request = new GraphRequest(
            graphType: $input->graphType ?? '',
            graphTitle: $input->graphTitle ?? '',
            // The script's defaults for the three ids are 0, which it printed in its errors.
            templateId: $input->graphTemplateId ?? '0',
            hostTemplateId: $input->hostTemplateId ?? '0',
            hostId: $input->hostId ?? '0',
            inputFields: $input->inputFields ?? '',
            queryId: $input->snmpQueryId ?? '',
            queryType: $input->snmpQueryTypeId ?? '',
            snmpFields: $input->snmpField,
            snmpValues: $input->snmpValue,
            snmpValueExpressions: $input->snmpValueRegex,
            reindexMethod: $reindex,
            force: $input->force,
            listTemplates: $input->listGraphTemplates,
            listInputFields: $input->listInputFields,
            listHosts: $input->listHosts,
            listQueries: $input->listSnmpQueries,
            listQueryTypes: $input->listQueryTypes,
            listFields: $input->listSnmpFields,
            listValues: $input->listSnmpValues,
        );
        try {
            $addition = ($this->addGraphs)($input->local, $input->as, $request);
        } catch (GraphManagementDenied) {
            return $this->renderer->denied($io, $output, $mode);
        } catch (\Throwable $error) {
            return $this->renderer->failed($io, $output, $mode, $error, 'Adding graphs failed');
        }

        return $this->report($io, $output, $mode, $legacy, $input->noHeaders, $request, $addition);
    }

    private function report(SymfonyStyle $io, OutputInterface $output, OutputMode $mode, AddGraphsLegacyArguments $legacy, bool $quiet, GraphRequest $request, GraphAddition $addition): int
    {
        $host = $request->hostId;
        $try = static fn(string $line): array => ['Try ' . $line];
        return match ($addition->status) {
            GraphAddition::TEMPLATES => $this->list($io, $output, $mode, 'graph_templates', ['ID', 'Name'], array_map(static fn($row): array => [$row->id, $row->name], $addition->choices), $legacy->templates($addition->choices, $quiet)),
            GraphAddition::INPUT_FIELDS => $this->list($io, $output, $mode, 'input_fields', ['Name', 'Default', 'Description'], array_map(static fn($row): array => [$row->key(), $row->default, $row->description], $addition->inputFields), $legacy->inputFields($addition->inputFields, $quiet)),
            GraphAddition::DEVICES => $this->list($io, $output, $mode, 'devices', ['ID', 'Hostname', 'Template', 'Description'], array_map(static fn($row): array => [$row->id, $row->hostname, $row->hostTemplateId, $row->description], $addition->devices), $legacy->devices($addition->devices, $quiet)),
            GraphAddition::QUERIES => $this->list($io, $output, $mode, 'snmp_queries', ['ID', 'Name'], array_map(static fn($row): array => [$row->id, $row->name], $addition->choices), $legacy->queries($addition->choices, $quiet)),
            GraphAddition::QUERY_TYPES => $this->list($io, $output, $mode, 'query_types', ['ID', 'Name'], array_map(static fn($row): array => [$row->id, $row->name], $addition->choices), $legacy->queryTypes($addition->choices, $quiet)),
            GraphAddition::FIELDS => $this->list($io, $output, $mode, 'snmp_fields', ['Name'], array_map(static fn(string $name): array => [$name], $addition->names), $legacy->fields($addition->names, $addition->subject, $quiet)),
            GraphAddition::VALUES => $this->list($io, $output, $mode, 'snmp_values', ['Value'], array_map(static fn(string $name): array => [$name], $addition->names), $legacy->values($addition->names, $addition->subject, $addition->repeats, $quiet)),
            GraphAddition::BAD_HOST_TEMPLATE => $this->refuse($io, $output, $mode, $addition->status, 'The --host-template-id option must name a device template that has graph templates.', ['ERROR: You must supply a valid --host-template-id before you can list its graph templates', 'Try --list-graph-template-id --host-template-id=[ID]'], [], true),
            GraphAddition::NEEDS_TEMPLATE => $this->refuse($io, $output, $mode, $addition->status, 'You must supply --graph-template-id to list its input fields.', ['ERROR: You must supply an graph-template-id before you can list its input fields', 'Try --graph-template-id=[ID] --list-input-fields'], [], true),
            GraphAddition::UNKNOWN_QUERY => $this->refuse($io, $output, $mode, $addition->status, 'Unknown data query ' . $addition->subject . '.', ['ERROR: Unknown snmp-query-id (' . $addition->subject . ')', ...$try('--list-snmp-queries')]),
            GraphAddition::UNKNOWN_QUERY_TYPE => $this->refuse($io, $output, $mode, $addition->status, 'Unknown data query graph type ' . $addition->subject . '.', ['ERROR: Unknown snmp-query-type-id (' . $addition->subject . ')', ...$try('--snmp-query-id=' . $request->queryId . ' --list-query-types')]),
            GraphAddition::UNKNOWN_HOST => $this->refuse($io, $output, $mode, $addition->status, 'Unknown device ' . $addition->subject . '.', ['ERROR: Unknown Host ID (' . $addition->subject . ')', ...$try('--list-hosts')]),
            GraphAddition::BOTH_VALUE_KINDS => $this->refuse($io, $output, $mode, $addition->status, 'Give --snmp-value or --snmp-value-regex, not both.', ['ERROR: You can\'t supply --snmp-value and --snmp-value-regex at the same time'], [], true),
            GraphAddition::VALUE_COUNT => $this->refuse($io, $output, $mode, $addition->status, 'Give as many --snmp-value options as --snmp-field options.', ['ERROR: number of --snmp-field and --snmp-value does not match'], [], true),
            GraphAddition::EXPRESSION_COUNT => $this->refuse($io, $output, $mode, $addition->status, 'Give as many --snmp-value-regex options as --snmp-field options.', ['ERROR: number of --snmp-field (' . $addition->subject . ') and --snmp-value-regex (' . $addition->detail . ') does not match'], [], true),
            GraphAddition::NEEDS_VALUE => $this->refuse($io, $output, $mode, $addition->status, 'Give --snmp-value or --snmp-value-regex with --snmp-field.', ['ERROR: You must supply a --snmp-value or --snmp-value-regex option with --snmp-field'], [], true),
            GraphAddition::NEEDS_FIELD => $this->refuse($io, $output, $mode, $addition->status, 'Give --snmp-field to list its values.', ['ERROR: You must supply an snmp-field before you can list its values', ...$try('--list-snmp-fields')], [], true),
            // The original named the whole list of fields here, which PHP printed as "Array".
            GraphAddition::UNKNOWN_FIELD => $this->refuse($io, $output, $mode, $addition->status, 'Unknown data query field for device ' . $addition->subject . '.', ['ERROR: Unknown snmp-field Array for host ' . $addition->subject, ...$try('--list-snmp-fields')]),
            GraphAddition::UNKNOWN_VALUE => $this->refuse($io, $output, $mode, $addition->status, 'Unknown value for field ' . $addition->subject . '.', ['ERROR: Unknown snmp-value for field ' . $addition->subject . ' - ' . $addition->detail, ...$try('--snmp-field=' . $addition->subject . ' --list-snmp-values')]),
            GraphAddition::UNKNOWN_TEMPLATE => $this->refuse($io, $output, $mode, $addition->status, 'Unknown graph template ' . $addition->subject . '.', ['ERROR: Unknown graph-template-id (' . $addition->subject . ')', ...$try('--list-graph-templates')]),
            GraphAddition::UNKNOWN_INPUT => $this->refuse($io, $output, $mode, $addition->status, 'Unknown input field ' . $addition->subject . '.', ['ERROR: Unknown input-field (' . $addition->subject . ')', ...$try('--list-input-fields')]),
            GraphAddition::NEEDS_OPTIONS => $this->refuse($io, $output, $mode, $addition->status, 'A data query graph needs --snmp-query-id, --snmp-query-type-id and --snmp-field.', ['ERROR: For graph-type of \'ds\' you must supply more options', ...$this->help($mode, $legacy)], [], true),
            GraphAddition::BAD_TYPE => $this->refuse($io, $output, $mode, $addition->status, 'The --graph-type option must be cg or ds.', ['ERROR: Graph Types must be either \'cg\' or \'ds\''], [], true),
            GraphAddition::NO_INDEXES => $this->refuse($io, $output, $mode, $addition->status, 'No data query index matches the given fields and values.', [
                'ERROR: Could not find one of more snmp-fields ' . implode(',', $addition->names) . ' with values (' . $addition->detail . ') for host-id ' . $host . ' (' . $addition->subject . ')',
                'Try --host-id=' . $host . ' --list-snmp-fields',
            ]),
            GraphAddition::EXISTS => $this->refuse($io, $output, $mode, $addition->status, 'The device has this graph already; use --force to create another.', array_map(static fn(GraphResult $graph): string => self::exists($graph), $addition->graphs)),
            GraphAddition::FAILED => $this->failed($io, $output, $mode, $addition),
            default => $this->done($io, $output, $mode, $addition),
        };
    }

    /**
     * @param list<list<int|string>> $rows
     * @param list<string> $columns
     * @param list<string> $lines what the original printed
     */
    private function list(SymfonyStyle $io, OutputInterface $output, OutputMode $mode, string $key, array $columns, array $rows, array $lines): int
    {
        if ($mode === OutputMode::Human) {
            $io->table($columns, $rows);

            return Command::SUCCESS;
        }
        $named = array_map(static fn(array $row): array => array_combine(array_map(static fn(string $column): string => strtolower($column), $columns), $row), $rows);

        return $this->renderer->render(new CommandResult(['status' => 'ok', $key => $named], $lines), $mode, $output);
    }

    private function done(SymfonyStyle $io, OutputInterface $output, OutputMode $mode, GraphAddition $addition): int
    {
        $lines = array_map(static fn(GraphResult $graph): string => self::line($graph), $addition->graphs);
        if ($mode === OutputMode::Human) {
            foreach ($lines as $line) {
                $io->writeln($line);
            }

            return Command::SUCCESS;
        }
        $json = ['status' => 'ok', 'database' => $this->database($addition->database), 'graphs' => array_map(static fn(GraphResult $graph): array => ['result' => $graph->kind, 'graph_id' => $graph->graphId, 'data_sources' => $graph->dataSources], $addition->graphs)];

        return $this->renderer->render(new CommandResult($json, $lines), $mode, $output);
    }

    /** A run that stopped on a failed write: what it did before that is still reported. */
    private function failed(SymfonyStyle $io, OutputInterface $output, OutputMode $mode, GraphAddition $addition): int
    {
        $lines = array_map(static fn(GraphResult $graph): string => self::line($graph), $addition->graphs);
        if ($mode === OutputMode::Human) {
            foreach ($lines as $line) {
                $io->writeln($line);
            }
        }

        return $this->refuse($io, $output, $mode, $addition->status, 'The graph could not be created.', $mode === OutputMode::Human ? [] : $lines, ['ERROR: Failed to create the graph.']);
    }

    private static function line(GraphResult $graph): string
    {
        return match ($graph->kind) {
            GraphResult::CREATED => 'Graph Added - Graph[' . $graph->graphId . '] - DS[' . $graph->dataSources . ']',
            GraphResult::EXISTS => self::exists($graph),
            default => 'Graph Not Added due to whitelist check failure.',
        };
    }

    private static function exists(GraphResult $graph): string
    {
        return 'NOTE: Not Adding Graph - this graph already exists - graph-id: (' . $graph->graphId . ') - data-source-id: (' . $graph->dataSources . ')';
    }

    /** @return list<string> */
    private function help(OutputMode $mode, AddGraphsLegacyArguments $legacy): array
    {
        return $mode === OutputMode::Legacy ? [$this->version->line(self::UTILITY), ...$legacy->help()] : [];
    }

    /**
     * The method as the original read it: a number from 0 to 3, or a name.
     * Null when it named none; the default is uptime.
     */
    private function reindex(?string $value): ?int
    {
        if ($value === null) {
            return 1;
        }
        if (is_numeric($value) && (float) $value >= 0 && (float) $value <= 3) {
            return (int) round((float) $value);
        }

        return match (strtolower($value)) {
            'none' => 0,
            'uptime' => 1,
            'index' => 2,
            'fields' => 3,
            default => null,
        };
    }

    /**
     * @param list<string> $stdout what the original printed on standard output
     * @param list<string> $stderr what it wrote to standard error
     */
    private function refuse(SymfonyStyle $io, OutputInterface $output, OutputMode $mode, string $status, string $human, array $stdout, array $stderr = [], bool $invalid = false): int
    {
        if ($mode === OutputMode::Legacy) {
            $errors = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
            foreach ($stderr as $line) {
                $errors->writeln($line, OutputInterface::OUTPUT_RAW);
            }
        }

        return $this->renderer->failure($io, $output, $mode, $human, new CommandResult(['status' => $status, 'error' => $human], $stdout, $mode === OutputMode::Legacy || !$invalid ? Command::FAILURE : Command::INVALID));
    }

    private function database(OperatorDatabase $database): string
    {
        return $database === OperatorDatabase::Main ? 'main' : 'local';
    }
}
