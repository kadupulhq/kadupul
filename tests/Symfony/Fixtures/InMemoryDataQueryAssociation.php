<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests\Fixtures;

use Kadupul\Graphing\Application\Port\CatalogChoice;
use Kadupul\Graphing\Application\Port\CatalogDevice;
use Kadupul\Graphing\Application\Port\DataQueryAssociationCatalog;
use Kadupul\Graphing\Application\Port\DataQueryAssociator;
use Kadupul\Graphing\Domain\ReindexMethod;
use Kadupul\IdentityAccess\Contract\OperatorDatabase;

/** One device (7, "core1"), two data queries, an existing association with the uptime method, and an associator that records its calls. */
final class InMemoryDataQueryAssociation implements DataQueryAssociationCatalog, DataQueryAssociator
{
    /** @var list<array{int, int, int|string}> */
    public array $associated = [];

    public function __construct(private readonly bool $fails = false) {}

    #[\Override]
    public function findDevice(OperatorDatabase $database, string $id): ?CatalogDevice
    {
        return is_numeric($id) && (float) $id === 7.0 ? new CatalogDevice(7, 'core1', 2, 'Core router') : null;
    }

    #[\Override]
    public function findDataQuery(OperatorDatabase $database, string $id): ?CatalogChoice
    {
        return match ((int) $id) {
            3 => new CatalogChoice(3, 'Interfaces'),
            4 => new CatalogChoice(4, 'Disks'),
            default => null,
        };
    }

    #[\Override]
    public function isAssociated(OperatorDatabase $database, int $deviceId, int $dataQueryId, ReindexMethod $method): bool
    {
        return $deviceId === 7 && $dataQueryId === 4 && $method->value === 1;
    }

    #[\Override]
    public function devices(OperatorDatabase $database): array
    {
        return [new CatalogDevice(7, 'core1', 2, 'Core router')];
    }

    #[\Override]
    public function dataQueries(OperatorDatabase $database): array
    {
        return [new CatalogChoice(3, 'Interfaces'), new CatalogChoice(4, 'Disks')];
    }

    #[\Override]
    public function associate(OperatorDatabase $database, int $deviceId, int $dataQueryId, ReindexMethod $method): void
    {
        $this->associated[] = [$deviceId, $dataQueryId, $method->value];
        if ($this->fails) {
            throw new \RuntimeException('The data query could not be associated.');
        }
    }
}
