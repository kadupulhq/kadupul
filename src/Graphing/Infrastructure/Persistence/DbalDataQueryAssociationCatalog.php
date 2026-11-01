<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Graphing\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;
use Kadupul\Graphing\Application\Port\CatalogChoice;
use Kadupul\Graphing\Application\Port\CatalogDevice;
use Kadupul\Graphing\Application\Port\DataQueryAssociationCatalog;
use Kadupul\Graphing\Domain\ReindexMethod;
use Kadupul\IdentityAccess\Contract\OperatorDatabase;

final readonly class DbalDataQueryAssociationCatalog implements DataQueryAssociationCatalog
{
    public function __construct(private Connection $localConnection, private Connection $mainConnection) {}

    #[\Override]
    public function findDevice(OperatorDatabase $database, string $id): ?CatalogDevice
    {
        // Bound as text, the server compares it as a number, as the original's unquoted literal was.
        $row = $this->connection($database)->fetchNumeric('SELECT id, hostname, description, host_template_id FROM host WHERE id = ? ORDER BY id LIMIT 1', [$id]);

        return $row === false ? null : self::device($row);
    }

    #[\Override]
    public function findDataQuery(OperatorDatabase $database, string $id): ?CatalogChoice
    {
        $row = $this->connection($database)->fetchNumeric('SELECT id, name FROM snmp_query WHERE id = ? ORDER BY id LIMIT 1', [$id]);

        return $row === false ? null : new CatalogChoice(self::int($row[0]), self::text($row[1]));
    }

    #[\Override]
    public function isAssociated(OperatorDatabase $database, int $deviceId, int $dataQueryId, ReindexMethod $method): bool
    {
        // The method is compared as a number, whatever way it was typed.
        return $this->connection($database)->fetchOne('SELECT host_id FROM host_snmp_query WHERE host_id = ? AND snmp_query_id = ? AND reindex_method = ?', [$deviceId, $dataQueryId, $method->value]) !== false;
    }

    #[\Override]
    public function devices(OperatorDatabase $database): array
    {
        $rows = $this->connection($database)->fetchAllNumeric('SELECT id, hostname, description, host_template_id FROM host ORDER BY id');

        return array_map(self::device(...), $rows);
    }

    #[\Override]
    public function dataQueries(OperatorDatabase $database): array
    {
        $rows = $this->connection($database)->fetchAllNumeric('SELECT id, name FROM snmp_query ORDER BY id');

        return array_map(static fn(array $row): CatalogChoice => new CatalogChoice(self::int($row[0]), self::text($row[1])), $rows);
    }

    private function connection(OperatorDatabase $database): Connection
    {
        return $database === OperatorDatabase::Main ? $this->mainConnection : $this->localConnection;
    }

    /** @param list<mixed> $row */
    private static function device(array $row): CatalogDevice
    {
        return new CatalogDevice(self::int($row[0]), self::text($row[1]), self::int($row[3]), self::text($row[2]));
    }

    private static function int(mixed $value): int
    {
        if (!is_numeric($value)) {
            throw new \UnexpectedValueException('The device tables returned an unexpected row.');
        }

        return (int) $value;
    }

    private static function text(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }
}
