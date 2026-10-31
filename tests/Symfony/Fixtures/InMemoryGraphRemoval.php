<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests\Fixtures;

use Kadupul\Graphing\Application\Port\GraphRemovalCatalog;
use Kadupul\Graphing\Application\Port\GraphRemover;
use Kadupul\Graphing\Application\Port\GraphTitle;
use Kadupul\Graphing\Application\Port\RemovalChoice;
use Kadupul\Graphing\Application\Port\RemovalDevice;
use Kadupul\Graphing\Domain\GraphRemovalFilter;
use Kadupul\IdentityAccess\Contract\OperatorDatabase;

/** A catalog of fixed graphs, devices and templates and a remover that records what it is asked to remove. */
final class InMemoryGraphRemoval implements GraphRemovalCatalog, GraphRemover
{
    /** @var list<list<int>> */
    public array $batches = [];

    /** @var list<bool> */
    public array $kept = [];

    /** @var list<GraphRemovalFilter> */
    public array $filters = [];

    /** @var list<list<int>> */
    public array $templateFilters = [];

    /**
     * @param list<GraphTitle> $graphs
     * @param list<RemovalDevice> $devices
     * @param list<RemovalChoice> $deviceTemplates
     * @param list<RemovalChoice> $graphTemplates
     */
    public function __construct(
        private readonly array $graphs = [],
        private readonly array $devices = [],
        private readonly array $deviceTemplates = [],
        private readonly array $graphTemplates = [],
        private readonly bool $fails = false,
    ) {}

    #[\Override]
    public function graphs(OperatorDatabase $database, GraphRemovalFilter $filter): array
    {
        $this->filters[] = $filter;

        return $this->graphs;
    }

    #[\Override]
    public function devices(OperatorDatabase $database, array $hostTemplateIds): array
    {
        $this->templateFilters[] = $hostTemplateIds;

        return $this->devices;
    }

    #[\Override]
    public function deviceTemplates(OperatorDatabase $database): array
    {
        return $this->deviceTemplates;
    }

    #[\Override]
    public function graphTemplates(OperatorDatabase $database, array $hostTemplateIds): array
    {
        $this->templateFilters[] = $hostTemplateIds;

        return $this->graphTemplates;
    }

    #[\Override]
    public function remove(OperatorDatabase $database, array $localGraphIds, bool $keepDataSources = false): int
    {
        $this->batches[] = $localGraphIds;
        $this->kept[] = $keepDataSources;
        if ($this->fails) {
            throw new \RuntimeException('Graph removal outcome is unknown.');
        }

        return count($localGraphIds);
    }
}
