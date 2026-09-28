<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Collection\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;
use Kadupul\Collection\Application\Port\DiscoveredDeviceCatalog;
use Kadupul\Collection\Application\ReadModel\DiscoveredDevicePage;
use Kadupul\Collection\Application\ReadModel\DiscoveredDeviceSummary;
use Kadupul\Collection\Application\ReadModel\DiscoveryNetworkChoice;
use Kadupul\Collection\Domain\DiscoveredDeviceCriteria;

final readonly class DoctrineDiscoveredDeviceCatalog implements DiscoveredDeviceCatalog
{
    public function __construct(private Connection $database) {}

    public function list(DiscoveredDeviceCriteria $criteria): DiscoveredDevicePage
    {
        $where = [];
        $parameters = [];
        if ($criteria->status !== 'all') {
            $where[] = 'd.up = ?';
            $parameters[] = $criteria->status === 'up' ? 1 : 0;
        }
        if ($criteria->networkId !== null) {
            $where[] = 'd.network_id = ?';
            $parameters[] = $criteria->networkId;
        }
        if ($criteria->snmp !== 'all') {
            $where[] = 'd.snmp = ?';
            $parameters[] = $criteria->snmp === 'up' ? 1 : 0;
        }
        if ($criteria->os !== '') {
            $where[] = 'd.os = ?';
            $parameters[] = $criteria->os;
        }
        if ($criteria->search !== '') {
            $where[] = '(d.hostname LIKE ? OR d.ip LIKE ? OR d.sysName LIKE ? OR d.sysDescr LIKE ? OR d.sysLocation LIKE ? OR d.sysContact LIKE ?)';
            $pattern = '%' . $criteria->search . '%';
            array_push($parameters, $pattern, $pattern, $pattern, $pattern, $pattern, $pattern);
        }

        $sort = [
            'hostname' => 'd.hostname',
            'ip' => 'd.ip',
            'sysName' => 'd.sysName',
            'sysLocation' => 'd.sysLocation',
            'sysContact' => 'd.sysContact',
            'sysDescr' => 'd.sysDescr',
            'os' => 'd.os',
            'time' => 'd.time',
            'snmp' => 'd.snmp',
            'up' => 'd.up',
        ][$criteria->sort];
        $direction = strtoupper($criteria->direction);
        $whereSql = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);
        $rows = $this->database->fetchAllAssociative("SELECT d.id, d.hostname, d.ip, d.sysName, d.sysLocation,
                d.sysContact, d.sysDescr, d.os, d.sysUptime, d.snmp, d.up,
                FROM_UNIXTIME(d.time) AS last_check
            FROM automation_devices d
            $whereSql
            ORDER BY $sort $direction, d.id $direction
            LIMIT " . $criteria->offset() . ',' . ($criteria->pageSize + 1), $parameters);

        $networkRows = $this->database->fetchAllAssociative('SELECT DISTINCT n.id, n.name
            FROM automation_networks n
            INNER JOIN automation_devices d ON d.network_id = n.id
            ORDER BY n.name, n.id');
        $networks = array_map(
            static fn(array $row): DiscoveryNetworkChoice => new DiscoveryNetworkChoice((int) $row['id'], (string) $row['name']),
            $networkRows
        );
        $operatingSystems = array_map('strval', $this->database->fetchFirstColumn('SELECT DISTINCT os
            FROM automation_devices
            WHERE os IS NOT NULL AND os != ""
            ORDER BY os'));

        $devices = [];
        foreach (array_slice($rows, 0, $criteria->pageSize) as $row) {
            $devices[] = new DiscoveredDeviceSummary(
                (int) $row['id'],
                (string) ($row['hostname'] ?? ''),
                (string) ($row['ip'] ?? ''),
                (string) ($row['sysName'] ?? ''),
                (string) ($row['sysLocation'] ?? ''),
                (string) ($row['sysContact'] ?? ''),
                (string) ($row['sysDescr'] ?? ''),
                (string) ($row['os'] ?? ''),
                intdiv((int) ($row['sysUptime'] ?? 0), 100),
                (int) ($row['snmp'] ?? 0) === 1,
                (int) ($row['up'] ?? 0) === 1,
                self::displayTimestamp($row['last_check'] ?? '')
            );
        }

        return new DiscoveredDevicePage($devices, $networks, $operatingSystems, count($rows) > $criteria->pageSize);
    }

    private static function displayTimestamp(mixed $timestamp): string
    {
        $timestamp = (string) ($timestamp ?? '');
        return strlen($timestamp) > 16 ? substr($timestamp, 0, 16) : $timestamp;
    }
}
