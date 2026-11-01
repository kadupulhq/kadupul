<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Graphing\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;
use Kadupul\Graphing\Application\Port\CatalogChoice;
use Kadupul\Graphing\Application\Port\DataSourceCreationCatalog;
use Kadupul\IdentityAccess\Contract\OperatorDatabase;

final readonly class DbalDataSourceCreationCatalog implements DataSourceCreationCatalog
{
    public function __construct(private Connection $localConnection, private Connection $mainConnection) {}

    #[\Override]
    public function findDevice(OperatorDatabase $database, string $id): ?CatalogChoice
    {
        // Bound as text, the server compares it as a number, as the original's unquoted literal was.
        return $this->choice($database, 'SELECT id, hostname FROM host WHERE id = ? ORDER BY id LIMIT 1', $id);
    }

    #[\Override]
    public function findDataTemplate(OperatorDatabase $database, string $id): ?CatalogChoice
    {
        return $this->choice($database, 'SELECT id, name FROM data_template WHERE id = ? ORDER BY id LIMIT 1', $id);
    }

    private function choice(OperatorDatabase $database, string $sql, string $id): ?CatalogChoice
    {
        $connection = $database === OperatorDatabase::Main ? $this->mainConnection : $this->localConnection;
        $row = $connection->fetchNumeric($sql, [$id]);
        if ($row === false) {
            return null;
        }
        if (!is_numeric($row[0])) {
            throw new \UnexpectedValueException('The data source tables returned an unexpected row.');
        }

        return new CatalogChoice((int) $row[0], is_string($row[1]) ? $row[1] : '');
    }
}
