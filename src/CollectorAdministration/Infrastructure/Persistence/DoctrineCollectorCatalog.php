<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\CollectorAdministration\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;
use Kadupul\CollectorAdministration\Application\Port\CollectorCatalog;
use Kadupul\CollectorAdministration\Application\ReadModel\CollectorPage;
use Kadupul\CollectorAdministration\Application\ReadModel\CollectorSummary;
use Kadupul\CollectorAdministration\Domain\CollectorListCriteria;
use Kadupul\CollectorAdministration\Domain\CollectorStatus;

final readonly class DoctrineCollectorCatalog implements CollectorCatalog
{
    public function __construct(private Connection $database) {}

    public function list(CollectorListCriteria $criteria): CollectorPage
    {
        $pollerType = $this->database->fetchOne('SELECT value FROM settings WHERE name = ?', ['poller_type']);
        $pollerType = $pollerType === false ? 1 : (int) $pollerType;
        $where = '';
        $parameters = [];
        if ($criteria->search !== '') {
            // Match the legacy pollers.php filter: name-only LIKE semantics,
            // including SQL wildcard characters entered by the operator.
            $where = ' WHERE p.name LIKE ?';
            $parameters = ['%' . $criteria->search . '%'];
        }

        $sort = [
            'name' => 'p.name',
            'id' => 'p.id',
            'hostname' => 'p.hostname',
            'status' => 'p.status',
            'hosts' => 'hosts',
            'polling_time' => 'p.total_time',
            'last_update' => 'p.last_update',
            'last_status' => 'p.last_status',
            'last_sync' => 'p.last_sync',
        ][$criteria->sort];
        $direction = strtoupper($criteria->direction);
        $rows = $this->database->fetchAllAssociative("SELECT p.id, p.name, p.hostname, p.disabled, p.status,
                p.processes, p.threads, p.total_time, p.avg_time, p.max_time,
                p.snmp, p.`script`, p.`server`, p.last_update, p.last_status, p.last_sync,
                UNIX_TIMESTAMP() - UNIX_TIMESTAMP(p.last_status) AS heartbeat,
                COUNT(h.id) AS hosts
            FROM poller p
            LEFT JOIN host h ON h.poller_id = p.id
            $where
            GROUP BY p.id
            ORDER BY $sort $direction, p.id $direction
            LIMIT " . $criteria->offset() . ',' . ($criteria->pageSize + 1), $parameters);

        $collectors = [];
        foreach (array_slice($rows, 0, $criteria->pageSize) as $row) {
            $name = (string) ($row['name'] ?? '');
            if (trim($name) === '') {
                $name = '<no name>';
            }
            $collectors[] = new CollectorSummary(
                (int) $row['id'],
                $name,
                (string) ($row['hostname'] ?? ''),
                CollectorStatus::fromLegacy((int) $row['status'], ($row['disabled'] ?? '') === 'on', (int) $row['heartbeat']),
                (int) $row['processes'],
                (int) $row['threads'],
                (float) $row['total_time'],
                (float) $row['avg_time'],
                (float) $row['max_time'],
                (int) $row['hosts'],
                (int) $row['snmp'],
                (int) $row['script'],
                (int) $row['server'],
                self::displayTimestamp($row['last_update'] ?? ''),
                self::displayTimestamp($row['last_status'] ?? ''),
                ($lastSync = self::displayTimestamp($row['last_sync'] ?? '')) === '' ? null : $lastSync
            );
        }

        return new CollectorPage($collectors, count($rows) > $criteria->pageSize, $pollerType);
    }

    private static function displayTimestamp(mixed $timestamp): string
    {
        $timestamp = (string) ($timestamp ?? '');
        return strlen($timestamp) > 5 ? substr($timestamp, 5) : $timestamp;
    }
}
