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
use Kadupul\IdentityAccess\Contract\OperatorDatabase;

/**
 * The device lookups and id/name rows the graph template and data query
 * catalogs share, read from the local or the main database as the operator
 * database selects.
 */
final readonly class OperatorDeviceCatalog
{
    public function __construct(private Connection $localConnection, private Connection $mainConnection) {}

    public function connection(OperatorDatabase $database): Connection
    {
        return $database === OperatorDatabase::Main ? $this->mainConnection : $this->localConnection;
    }

    public function findDevice(OperatorDatabase $database, string $id): ?CatalogDevice
    {
        // Bound as text, the server compares it as a number, as the original's unquoted literal was.
        $row = $this->connection($database)->fetchNumeric('SELECT id, hostname, description, host_template_id FROM host WHERE id = ? ORDER BY id LIMIT 1', [$id]);

        return $row === false ? null : self::device($row);
    }

    /** @return list<CatalogDevice> */
    public function devices(OperatorDatabase $database): array
    {
        $rows = $this->connection($database)->fetchAllNumeric('SELECT id, hostname, description, host_template_id FROM host ORDER BY id');

        return array_map(self::device(...), $rows);
    }

    /** @param list<mixed>|false $row an id and name row */
    public static function choice(array|false $row): ?CatalogChoice
    {
        return $row === false ? null : self::toChoice($row);
    }

    /**
     * @param list<list<mixed>> $rows id and name rows
     * @return list<CatalogChoice>
     */
    public static function choices(array $rows): array
    {
        return array_map(self::toChoice(...), $rows);
    }

    /** @param list<mixed> $row */
    private static function toChoice(array $row): CatalogChoice
    {
        return new CatalogChoice(self::int($row[0]), self::text($row[1]));
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
