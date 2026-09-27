<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Collection\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;
use Kadupul\Collection\Application\Port\NetworkCatalog;
use Kadupul\Collection\Application\ReadModel\NetworkPage;
use Kadupul\Collection\Application\ReadModel\NetworkSummary;
use Kadupul\Collection\Domain\NetworkListCriteria;

final readonly class DoctrineNetworkCatalog implements NetworkCatalog
{
    public function __construct(private Connection $database) {}

    public function list(NetworkListCriteria $criteria): NetworkPage
    {
        $where = '';
        $parameters = [];
        if ($criteria->search !== '') {
            $where = ' WHERE n.name LIKE ?';
            $parameters[] = '%' . $criteria->search . '%';
        }
        $sort = [
            'name' => 'n.name',
            'data_collector' => 'p.name',
            'sched_type' => 'n.sched_type',
            'total_ips' => 'n.total_ips',
            'threads' => 'n.threads',
            'last_runtime' => 'n.last_runtime',
            'last_started' => 'n.last_started',
        ][$criteria->sort];
        $direction = strtoupper($criteria->direction);
        $rows = $this->database->fetchAllAssociative("SELECT n.id, n.name, n.poller_id, n.sched_type,
                n.total_ips, n.enabled, n.up_hosts, n.snmp_hosts, n.threads, n.last_runtime,
                n.start_at, n.next_start, n.last_started, p.name AS data_collector,
                COALESCE(proc.active, 0) AS active_processes,
                COALESCE(ip.pending, 0) AS pending_ips,
                COALESCE(ip.running, 0) AS running_ips,
                COALESCE(ip.done, 0) AS done_ips,
                proc.process_up AS process_up, proc.process_snmp AS process_snmp,
                COALESCE(ip.total, 0) AS ip_total
            FROM automation_networks n
            LEFT JOIN poller p ON p.id = n.poller_id
            LEFT JOIN (
                SELECT network_id,
                    SUM(CASE WHEN status != 'done' THEN 1 ELSE 0 END) AS active,
                    SUM(up_hosts) AS process_up,
                    SUM(snmp_hosts) AS process_snmp
                FROM automation_processes
                GROUP BY network_id
            ) proc ON proc.network_id = n.id
            LEFT JOIN (
                SELECT network_id, COUNT(*) AS total,
                    SUM(CASE WHEN status = 0 THEN 1 ELSE 0 END) AS pending,
                    SUM(CASE WHEN status = 1 THEN 1 ELSE 0 END) AS running,
                    SUM(CASE WHEN status = 2 THEN 1 ELSE 0 END) AS done
                FROM automation_ips
                GROUP BY network_id
            ) ip ON ip.network_id = n.id
            $where
            ORDER BY $sort $direction, n.id $direction
            LIMIT " . $criteria->offset() . ',' . ($criteria->pageSize + 1), $parameters);

        $networks = [];
        foreach (array_slice($rows, 0, $criteria->pageSize) as $row) {
            $enabled = (string) ($row['enabled'] ?? '') === 'on';
            $active = (int) $row['active_processes'] > 0;
            $status = !$enabled ? 'Disabled' : ($active ? 'Running' : 'Idle');
            if (!$enabled) {
                $progress = '0/0/0';
                $upHosts = 0;
                $snmpHosts = 0;
            } elseif ($active) {
                $progress = (int) $row['ip_total'] === 0
                    ? '0/0/0'
                    : (int) $row['pending_ips'] . '/' . (int) $row['running_ips'] . '/' . (int) $row['done_ips'];
                $upHosts = (int) ($row['process_up'] ?? 0);
                $snmpHosts = $upHosts === 0 ? 0 : (int) ($row['process_snmp'] ?? 0);
            } else {
                $progress = '0/0/0';
                $upHosts = (int) ($row['up_hosts'] ?? 0);
                $snmpHosts = (int) ($row['snmp_hosts'] ?? 0);
            }

            $manual = (int) $row['sched_type'] === 1;
            $nextStart = !$enabled || $manual
                ? null
                : self::datePrefix(($row['next_start'] ?? '') === '0000-00-00 00:00:00' ? $row['start_at'] : $row['next_start']);
            $lastStarted = ($row['last_started'] ?? '') === '0000-00-00 00:00:00'
                ? null
                : self::datePrefix($row['last_started']);

            $networks[] = new NetworkSummary(
                (int) $row['id'],
                (string) $row['name'],
                (string) ($row['data_collector'] ?? ''),
                match ((int) $row['sched_type']) {
                    1 => 'Manual', 2 => 'Daily', 3 => 'Weekly', 4 => 'Monthly', 5 => 'Monthly on Day', default => 'Unknown',
                },
                (int) ($row['total_ips'] ?? 0),
                $status,
                $progress,
                $upHosts,
                $snmpHosts,
                (int) ($row['threads'] ?? 0),
                round((float) ($row['last_runtime'] ?? 0), 2),
                $nextStart,
                $lastStarted
            );
        }

        return new NetworkPage($networks, count($rows) > $criteria->pageSize);
    }

    private static function datePrefix(mixed $value): ?string
    {
        $value = (string) ($value ?? '');
        return $value === '' ? null : substr($value, 0, 16);
    }
}
