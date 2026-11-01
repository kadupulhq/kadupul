<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests\Fixtures;

use Kadupul\Graphing\Application\Port\CatalogChoice;
use Kadupul\Graphing\Application\Port\DataSourceCreationCatalog;
use Kadupul\Graphing\Application\Port\DataSourceCreator;
use Kadupul\IdentityAccess\Contract\OperatorDatabase;

/** One device (7, "core1"), one data template (3, "Traffic"), and a creator that records its calls and hands out id 41. */
final class InMemoryDataSourceCreation implements DataSourceCreationCatalog, DataSourceCreator
{
    /** @var list<array{int, int, bool}> */
    public array $created = [];

    public function __construct(private readonly bool $fails = false) {}

    #[\Override]
    public function findDevice(OperatorDatabase $database, string $id): ?CatalogChoice
    {
        return is_numeric($id) && (float) $id === 7.0 ? new CatalogChoice(7, 'core1') : null;
    }

    #[\Override]
    public function findDataTemplate(OperatorDatabase $database, string $id): ?CatalogChoice
    {
        return is_numeric($id) && (float) $id === 3.0 ? new CatalogChoice(3, 'Traffic') : null;
    }

    #[\Override]
    public function create(OperatorDatabase $database, int $deviceId, int $dataTemplateId, bool $pushOut): int
    {
        $this->created[] = [$deviceId, $dataTemplateId, $pushOut];
        if ($this->fails) {
            throw new \RuntimeException('The data source could not be added.');
        }

        return 41;
    }
}
