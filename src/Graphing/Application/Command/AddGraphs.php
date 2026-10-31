<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Graphing\Application\Command;

use Kadupul\Graphing\Application\Port\CatalogChoice;
use Kadupul\Graphing\Application\Port\CreatedGraph;
use Kadupul\Graphing\Application\Port\GraphCreationCatalog;
use Kadupul\Graphing\Application\Port\GraphDraft;
use Kadupul\Graphing\Application\Port\GraphWriter;
use Kadupul\Graphing\Application\Port\InputField;
use Kadupul\Graphing\Application\Port\SnmpFilter;
use Kadupul\IdentityAccess\Contract\OperatorDatabase;

/**
 * add_graphs.php: the lists it offered, the checks it made in the order it made
 * them, and the creation of graphs from a template or from the indexes of a data
 * query. The ids and texts are compared as PHP compared them: an id must be the
 * canonical form of an array key, a value matches by loose equality.
 */
final readonly class AddGraphs
{
    private const string ACTION = 'graph.add';

    /** What PHP accepts as an integer array key: the form (string) of that integer would take. */
    private const string CANONICAL_KEY = '/^(?:0|-?[1-9][0-9]*)$/';

    public function __construct(private GraphCommandAccess $access, private GraphCreationCatalog $catalog, private GraphWriter $writer) {}

    /**
     * @param ?string $operator account to act as; null means the admin_user setting
     * @throws GraphManagementDenied
     */
    public function __invoke(bool $local, ?string $operator, GraphRequest $request): GraphAddition
    {
        $scope = $this->access->select(self::ACTION, $local, $operator, !$request->listsOnly());
        $database = $scope->database;
        $end = static fn(string $status, string $subject = '', string $detail = ''): GraphAddition => new GraphAddition($database, $status, $subject, $detail);

        if ($request->listTemplates) {
            if (self::positive($request->hostTemplateId)) {
                $templates = $this->catalog->graphTemplatesOfDeviceTemplate($database, $request->hostTemplateId);
                if ($templates === null || $templates === []) {
                    return $end(GraphAddition::BAD_HOST_TEMPLATE);
                }

                return new GraphAddition($database, GraphAddition::TEMPLATES, choices: $templates);
            }

            return new GraphAddition($database, GraphAddition::TEMPLATES, choices: $this->catalog->graphTemplates($database));
        }
        if ($request->listInputFields) {
            if (!self::positive($request->templateId)) {
                return $end(GraphAddition::NEEDS_TEMPLATE);
            }

            return new GraphAddition($database, GraphAddition::INPUT_FIELDS, inputFields: $this->catalog->inputFields($database, $request->templateId));
        }
        $devices = $this->catalog->devices($database);
        if ($request->listHosts) {
            return new GraphAddition($database, GraphAddition::DEVICES, devices: $devices);
        }
        $queries = $this->catalog->queries($database);
        if ($request->listQueries) {
            return new GraphAddition($database, GraphAddition::QUERIES, choices: $queries);
        }
        $queryId = null;
        if ($request->queryId !== '') {
            $queryId = self::choice($queries, $request->queryId);
            if ($queryId === null) {
                return $end(GraphAddition::UNKNOWN_QUERY, $request->queryId);
            }
            $types = $this->catalog->queryTypes($database, $queryId);
            if ($request->listQueryTypes) {
                return new GraphAddition($database, GraphAddition::QUERY_TYPES, choices: $types);
            }
            if ($request->queryType !== '' && self::choice($types, $request->queryType) === null) {
                return $end(GraphAddition::UNKNOWN_QUERY_TYPE, $request->queryType);
            }
        }

        $hostname = null;
        $deviceId = 0;
        foreach ($devices as $device) {
            if ((string) $device->id === $request->hostId && $device->id !== 0) {
                $deviceId = $device->id;
                $hostname = $device->hostname;
            }
        }
        if ($hostname === null) {
            return $end(GraphAddition::UNKNOWN_HOST, $request->hostId);
        }

        $correlation = $this->access->correlation();
        // A data query that is not yet on the device is put there and run once, so the cache is filled before any graph is made from it.
        if ($queryId !== null && !$request->listFields && !$request->listValues && !$this->catalog->queryAssociated($database, $deviceId, $queryId)) {
            try {
                $this->writer->associateQuery($database, $deviceId, $queryId, $request->reindexMethod);
                $this->access->change($correlation, $scope, self::ACTION, 'device-query', $deviceId . ':' . $queryId, true);
            } catch (\RuntimeException) {
                $this->access->change($correlation, $scope, self::ACTION, 'device-query', $deviceId . ':' . $queryId, false);

                return $end(GraphAddition::FAILED);
            }
        }

        // 'dq' is not a graph type the script created anything for, so only the list modes ever reach this check.
        if ($request->graphType === 'dq' || $request->listFields || $request->listValues) {
            $checked = $this->checkFields($database, $request, $deviceId, $queryId);
            if ($checked instanceof GraphAddition) {
                return $checked;
            }
        }

        if (self::choice($this->catalog->graphTemplates($database), $request->templateId) === null) {
            return $end(GraphAddition::UNKNOWN_TEMPLATE, $request->templateId);
        }
        $templateId = (int) $request->templateId;

        $custom = [];
        if ($request->inputFields !== '') {
            $custom = $this->customData($database, $request, $templateId);
            if (is_string($custom)) {
                return $end(GraphAddition::UNKNOWN_INPUT, $custom);
            }
        }

        if ($request->graphType === 'cg') {
            return $this->plain($scope->database, $scope, $correlation, $request, $templateId, $deviceId, $custom);
        }
        if ($request->graphType === 'ds') {
            return $this->fromQuery($scope->database, $scope, $correlation, $request, $templateId, $deviceId, $hostname, $custom);
        }

        return $end(GraphAddition::BAD_TYPE);
    }

    /**
     * The checks the original made of --snmp-field, --snmp-value and --snmp-value-regex
     * for a data query graph or a list of fields or values.
     *
     * @return GraphAddition|null null when every check passed
     */
    private function checkFields(OperatorDatabase $database, GraphRequest $request, int $deviceId, ?int $queryId): ?GraphAddition
    {
        $fields = $this->catalog->snmpFields($database, $deviceId, $queryId);
        if ($request->listFields) {
            return new GraphAddition($database, GraphAddition::FIELDS, (string) $deviceId, names: $fields);
        }
        if ($request->snmpValues !== [] && $request->snmpValueExpressions !== []) {
            return new GraphAddition($database, GraphAddition::BOTH_VALUE_KINDS);
        }
        $fieldCount = count($request->snmpFields);
        if ($request->snmpValues !== []) {
            if ($fieldCount !== count($request->snmpValues)) {
                return new GraphAddition($database, GraphAddition::VALUE_COUNT);
            }
        } elseif ($request->snmpValueExpressions !== []) {
            if ($fieldCount !== count($request->snmpValueExpressions)) {
                return new GraphAddition($database, GraphAddition::EXPRESSION_COUNT, (string) $fieldCount, (string) count($request->snmpValueExpressions));
            }
        } elseif (!$request->listValues) {
            return new GraphAddition($database, GraphAddition::NEEDS_VALUE);
        }

        $known = [];
        foreach ($request->snmpFields as $index => $field) {
            if ($field !== '' && !in_array($field, $fields, true)) {
                return new GraphAddition($database, GraphAddition::UNKNOWN_FIELD, (string) $deviceId);
            }
            $known = $this->catalog->snmpValues($database, $deviceId, $field, $queryId);
            $value = '';
            $expression = '';
            if ($request->snmpValues !== []) {
                $value = $request->snmpValues[$index];
            } else {
                $expression = $request->snmpValueExpressions[$index] ?? '';
            }
            if (self::truthy($value)) {
                $found = false;
                foreach ($known as $candidate) {
                    $found = $found || self::looseEquals($value, $candidate);
                }
                if (!$found) {
                    return new GraphAddition($database, GraphAddition::UNKNOWN_VALUE, $field, $value);
                }
            } elseif (self::truthy($expression)) {
                $found = false;
                foreach ($known as $candidate) {
                    $found = $found || @preg_match('/' . $expression . '/i', $candidate) === 1;
                }
                // The original reported the (empty) value here, not the expression.
                if (!$found) {
                    return new GraphAddition($database, GraphAddition::UNKNOWN_VALUE, $field, '');
                }
            }
        }

        if ($request->listValues) {
            if ($request->snmpFields === []) {
                return new GraphAddition($database, GraphAddition::NEEDS_FIELD);
            }

            // The original printed the values of the last field once for each field, under an empty field name.
            return new GraphAddition($database, GraphAddition::VALUES, (string) $deviceId, names: $known, repeats: count($request->snmpFields));
        }

        return null;
    }

    /**
     * Each --input-fields word is [data-template-id:]name=value. A name matches any
     * field whose key contains it; the value is whatever follows the first "=".
     *
     * @return list<array{0: string, 1: ?string, 2: ?string}>|string the entries, or the name of an unknown field
     */
    private function customData(OperatorDatabase $database, GraphRequest $request, int $templateId): array|string
    {
        $fields = [];
        foreach ($this->catalog->inputFields($database, (string) $templateId) as $field) {
            $fields[$field->key()] = $field;
        }
        $entries = [];
        foreach (explode(' ', $request->inputFields) as $word) {
            $parts = explode('=', $word);
            $dataTemplate = '0';
            if (substr_count($parts[0], ':') > 0) {
                $compound = explode(':', $parts[0]);
                $dataTemplate = $compound[0];
                $name = $compound[1];
            } else {
                $name = $parts[0];
            }
            $match = null;
            foreach ($fields as $key => $field) {
                if ($name !== '' && str_contains($key, $name)) {
                    $match = $field;
                    break;
                }
            }
            if (!$match instanceof InputField) {
                return $name;
            }
            if (self::zero($dataTemplate)) {
                $dataTemplate = (string) $match->dataTemplateId;
            }
            // A name that only matched part of a key finds no field of its own; the original then filed the value under no field.
            $own = $fields[$dataTemplate . ':' . $name] ?? null;
            $entries[] = [$dataTemplate, $own === null ? null : (string) $own->dataInputFieldId, $parts[1] ?? null];
        }

        return $entries;
    }

    /**
     * @param list<array{0: string, 1: ?string, 2: ?string}> $custom
     */
    private function plain(OperatorDatabase $database, GraphCommandScope $scope, string $correlation, GraphRequest $request, int $templateId, int $deviceId, array $custom): GraphAddition
    {
        $existing = $this->catalog->existingGraph($database, $templateId, $deviceId, null, null);
        if ($existing !== null && $existing > 0 && !$request->force) {
            return new GraphAddition($database, GraphAddition::EXISTS, graphs: [new GraphResult(GraphResult::EXISTS, $existing, (string) $this->catalog->firstDataSource($database, $existing))]);
        }
        $result = $this->create($database, $scope, $correlation, new GraphDraft($templateId, $deviceId, null, null, null, $custom, $request->graphTitle));

        return new GraphAddition($database, $result === null ? GraphAddition::FAILED : GraphAddition::DONE, graphs: $result === null ? [] : [$result]);
    }

    /**
     * @param list<array{0: string, 1: ?string, 2: ?string}> $custom
     */
    private function fromQuery(OperatorDatabase $database, GraphCommandScope $scope, string $correlation, GraphRequest $request, int $templateId, int $deviceId, string $hostname, array $custom): GraphAddition
    {
        if ($request->queryId === '' || $request->queryType === '' || $request->snmpFields === []) {
            return new GraphAddition($database, GraphAddition::NEEDS_OPTIONS);
        }
        $queryId = (int) $request->queryId;
        $filters = [];
        $complete = true;
        foreach ($request->snmpFields as $index => $field) {
            $value = $request->snmpValues[$index] ?? null;
            $expression = $request->snmpValueExpressions[$index] ?? null;
            // A field with neither made the original's query invalid, so it found no index.
            $complete = $complete && ($value !== null || $expression !== null);
            $filters[] = new SnmpFilter($field, $value, $value === null ? $expression : null);
        }
        $indexes = $complete ? $this->catalog->snmpIndexes($database, $deviceId, $queryId, $filters) : [];
        if ($indexes === []) {
            $values = $request->snmpValues !== [] ? $request->snmpValues : $request->snmpValueExpressions;

            return new GraphAddition($database, GraphAddition::NO_INDEXES, $hostname, implode(',', $values), names: $request->snmpFields);
        }

        $graphs = [];
        foreach ($indexes as $index) {
            $existing = $this->catalog->existingGraph($database, $templateId, $deviceId, $queryId, $index);
            if ($existing !== null && $existing > 0) {
                try {
                    if ($request->graphTitle !== '') {
                        $this->writer->retitle($database, $existing, $request->graphTitle);
                        $this->access->change($correlation, $scope, self::ACTION, 'graph', (string) $existing, true);
                    }
                } catch (\RuntimeException) {
                    $this->access->change($correlation, $scope, self::ACTION, 'graph', (string) $existing, false);

                    return new GraphAddition($database, GraphAddition::FAILED, graphs: $graphs);
                }
                $graphs[] = new GraphResult(GraphResult::EXISTS, $existing, (string) $this->catalog->firstDataSource($database, $existing));
                continue;
            }
            $result = $this->create($database, $scope, $correlation, new GraphDraft($templateId, $deviceId, $queryId, (int) $request->queryType, $index, $custom, $request->graphTitle));
            if ($result === null) {
                return new GraphAddition($database, GraphAddition::FAILED, graphs: $graphs);
            }
            $graphs[] = $result;
        }

        return new GraphAddition($database, GraphAddition::DONE, graphs: $graphs);
    }

    /**
     * @return ?GraphResult null when the writer failed
     */
    private function create(OperatorDatabase $database, GraphCommandScope $scope, string $correlation, GraphDraft $draft): ?GraphResult
    {
        try {
            $created = $this->writer->createGraph($database, $draft);
        } catch (\RuntimeException) {
            $this->access->change($correlation, $scope, self::ACTION, 'graph', '0', false);

            return null;
        }
        if ($created === null) {
            return new GraphResult(GraphResult::REFUSED);
        }
        $this->access->change($correlation, $scope, self::ACTION, 'graph', (string) $created->graphId, true);

        return new GraphResult(GraphResult::CREATED, $created->graphId, self::dataSources($created));
    }

    /** The first data source the graph reads, if there is one, then each one the creation made, joined by ", ". */
    private static function dataSources(CreatedGraph $created): string
    {
        $text = $created->firstDataSource === null ? '' : (string) $created->firstDataSource;
        foreach ($created->dataSourceIds as $id) {
            $text = $text !== '' ? $text . ', ' . $id : (string) $id;
        }

        return $text;
    }

    /**
     * @param list<CatalogChoice> $choices
     */
    private static function choice(array $choices, string $id): ?int
    {
        if (preg_match(self::CANONICAL_KEY, $id) !== 1) {
            return null;
        }
        foreach ($choices as $choice) {
            if ((string) $choice->id === $id) {
                return $choice->id;
            }
        }

        return null;
    }

    /** PHP's `$value > 0` for text: numbers by value, anything else as a string against "0". */
    private static function positive(string $value): bool
    {
        return is_numeric($value) ? (float) $value > 0.0 : strcmp($value, '0') > 0;
    }

    /** PHP's `$value == 0` for text. */
    private static function zero(string $value): bool
    {
        return is_numeric($value) && (float) $value === 0.0;
    }

    /** PHP's truthiness of text: neither empty nor "0". */
    private static function truthy(string $value): bool
    {
        return $value !== '' && $value !== '0';
    }

    /** PHP's `==` between two strings: numeric strings by value, others byte for byte. */
    private static function looseEquals(string $left, string $right): bool
    {
        return is_numeric($left) && is_numeric($right) ? (float) $left === (float) $right : $left === $right;
    }
}
