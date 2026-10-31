<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests\Fixtures;

use Kadupul\Graphing\Application\Port\CatalogChoice;
use Kadupul\Graphing\Application\Port\CatalogDevice;
use Kadupul\Graphing\Application\Port\CreatedGraph;
use Kadupul\Graphing\Application\Port\GraphCreationCatalog;
use Kadupul\Graphing\Application\Port\GraphDraft;
use Kadupul\Graphing\Application\Port\GraphWriter;
use Kadupul\Graphing\Application\Port\InputField;
use Kadupul\IdentityAccess\Contract\OperatorDatabase;

/**
 * Devices 7 "core1" (with query 1 cached) and 8 "sw1", graph templates 3 "Traffic" and 4 "Load",
 * data query 1 with graph type 2, and a writer that records what it is given.
 */
final class InMemoryGraphs implements GraphCreationCatalog, GraphWriter
{
    /** @var list<string> */
    public array $associations = [];

    /** @var list<GraphDraft> */
    public array $drafts = [];

    /** @var list<string> */
    public array $titles = [];

    /** @var list<\Kadupul\Graphing\Application\Port\SnmpFilter> */
    public array $filters = [];

    /**
     * @param list<string> $indexes what the cache holds for the filters
     * @param array<string, int> $existing "template:device[:index]" => graph id
     * @param int $refuseFrom the creation (counting from 1) the whitelist refuses, and every one after it; 0 for none
     * @param int $failFrom the write (counting from 1) that fails, and every one after it; 0 for none
     */
    public function __construct(private readonly array $indexes = ['1', '2'], private readonly array $existing = [], private readonly int $refuseFrom = 0, private readonly int $failFrom = 0) {}

    #[\Override]
    public function devices(OperatorDatabase $database): array
    {
        return [new CatalogDevice(7, 'core1', 2, 'Core router'), new CatalogDevice(8, 'sw1', 0, 'Switch')];
    }

    #[\Override]
    public function graphTemplates(OperatorDatabase $database): array
    {
        return [new CatalogChoice(3, 'Traffic'), new CatalogChoice(4, 'Load')];
    }

    #[\Override]
    public function graphTemplatesOfDeviceTemplate(OperatorDatabase $database, string $hostTemplateId): ?array
    {
        if (!is_numeric($hostTemplateId)) {
            return null;
        }

        return (float) $hostTemplateId === 2.0 ? [new CatalogChoice(4, 'Load')] : [];
    }

    #[\Override]
    public function inputFields(OperatorDatabase $database, string $templateId): array
    {
        return $templateId === '4' ? [new InputField('index_type', 'Index Type', '', 9, 21), new InputField('index_value', 'Index Value', 'x', 9, 22)] : [];
    }

    #[\Override]
    public function queries(OperatorDatabase $database): array
    {
        return [new CatalogChoice(1, 'Interfaces')];
    }

    #[\Override]
    public function queryTypes(OperatorDatabase $database, int $queryId): array
    {
        return [new CatalogChoice(2, 'Traffic')];
    }

    #[\Override]
    public function snmpFields(OperatorDatabase $database, int $deviceId, ?int $queryId): array
    {
        return $deviceId === 7 ? ['ifName', 'ifStatus'] : [];
    }

    #[\Override]
    public function snmpValues(OperatorDatabase $database, int $deviceId, string $field, ?int $queryId): array
    {
        return match ($field) {
            'ifName' => ['eth0', 'eth1', '1'],
            'ifStatus' => ['Up', 'Down'],
            default => [],
        };
    }

    #[\Override]
    public function queryAssociated(OperatorDatabase $database, int $deviceId, int $queryId): bool
    {
        return $deviceId === 7;
    }

    #[\Override]
    public function existingGraph(OperatorDatabase $database, int $templateId, int $deviceId, ?int $queryId, ?string $queryIndex): ?int
    {
        return $this->existing[$templateId . ':' . $deviceId . ($queryIndex === null ? '' : ':' . $queryIndex)] ?? null;
    }

    #[\Override]
    public function firstDataSource(OperatorDatabase $database, int $graphId): ?int
    {
        return $graphId + 1000;
    }

    #[\Override]
    public function snmpIndexes(OperatorDatabase $database, int $deviceId, int $queryId, array $filters): array
    {
        $this->filters = $filters;

        return $this->indexes;
    }

    #[\Override]
    public function associateQuery(OperatorDatabase $database, int $deviceId, int $queryId, int $reindexMethod): void
    {
        $this->write();
        $this->associations[] = $deviceId . ':' . $queryId . ':' . $reindexMethod;
    }

    #[\Override]
    public function createGraph(OperatorDatabase $database, GraphDraft $graph): ?CreatedGraph
    {
        $this->write();
        $this->drafts[] = $graph;
        $number = count($this->drafts);
        if ($this->refuseFrom > 0 && $number >= $this->refuseFrom) {
            return null;
        }

        return new CreatedGraph(100 + $number, $graph->queryId === null ? null : 500 + $number, [200 + $number]);
    }

    #[\Override]
    public function retitle(OperatorDatabase $database, int $graphId, string $title): void
    {
        $this->write();
        $this->titles[] = $graphId . ':' . $title;
    }

    private int $writes = 0;

    private function write(): void
    {
        ++$this->writes;
        if ($this->failFrom > 0 && $this->writes >= $this->failFrom) {
            throw new \RuntimeException('The write failed.');
        }
    }
}
