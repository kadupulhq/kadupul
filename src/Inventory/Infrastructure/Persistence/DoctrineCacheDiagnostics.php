<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2004-2026 The Cacti Group
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace Kadupul\Inventory\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;
use Kadupul\Inventory\Contract\CacheDiagnostics;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsAlias(CacheDiagnostics::class)]
final readonly class DoctrineCacheDiagnostics implements CacheDiagnostics
{
    public function __construct(
        #[Autowire(service: 'doctrine.dbal.web_connection')]
        private Connection $database,
    ) {}

    public function read(bool $poller, int $actorId, array $filters, int $rows, int $offset): array
    {
        if ($actorId < 1 || $rows < 1 || $rows > 100 || $offset < 0 || $offset > 9999800) {
            throw new \InvalidArgumentException('Invalid cache selection.');
        }
        $scope = $this->scope($actorId);
        $parameters = [];
        if ($poller) {
            // Arguments and SNMP credentials are intentionally not selected: command
            // arguments can embed secrets even when credential columns are omitted.
            $select = "SELECT COALESCE(dtd.name_cache, ''), COALESCE(h.description, ''), pi.host_id,
                pi.local_data_id, pi.action, pi.rrd_name, pi.rrd_path, pi.rrd_step, pi.rrd_next_step";
            $from = ' FROM poller_item pi INNER JOIN data_local dl ON dl.id = pi.local_data_id
                LEFT JOIN data_template_data dtd ON dtd.local_data_id = pi.local_data_id
                LEFT JOIN host h ON h.id = pi.host_id';
            $where = "(pi.host_id = 0 OR pi.host_id IN ($scope))";
            foreach (['host_id' => 'pi.host_id', 'template_id' => 'dl.data_template_id', 'poller_action' => 'pi.action'] as $key => $column) {
                if ($filters[$key] !== '-1') {
                    $where .= " AND $column = ?";
                    $parameters[] = (int) $filters[$key];
                }
            }
            if ($filters['status'] === '0') {
                $where .= " AND (h.disabled = 'on' OR dtd.active = '')";
            } elseif ($filters['status'] === '1') {
                $where .= " AND h.disabled = '' AND dtd.active = 'on'";
            }
            $search = ['dtd.name_cache', 'h.description', 'pi.rrd_name', 'pi.rrd_path'];
            $order = 'dtd.name_cache, pi.local_data_id, pi.rrd_name';
        } else {
            $select = 'SELECT h.description, sq.name, hsc.snmp_index, hsc.field_name, hsc.field_value, hsc.oid';
            $from = ' FROM host_snmp_cache hsc INNER JOIN host h ON h.id = hsc.host_id
                INNER JOIN snmp_query sq ON sq.id = hsc.snmp_query_id';
            $where = "hsc.host_id IN ($scope)";
            foreach (['host_id' => 'hsc.host_id', 'snmp_query_id' => 'hsc.snmp_query_id'] as $key => $column) {
                if ($filters[$key] !== '-1') {
                    $where .= " AND $column = ?";
                    $parameters[] = (int) $filters[$key];
                }
            }
            $search = ['h.description', 'sq.name', 'hsc.field_name', 'hsc.field_value', 'hsc.oid'];
            if ($filters['with_index'] === '1') {
                $search[] = 'hsc.snmp_index';
            }
            $order = 'hsc.host_id, hsc.snmp_query_id, hsc.field_name, hsc.snmp_index';
        }
        if ($filters['filter'] !== '') {
            $where .= ' AND (' . implode(' OR ', array_map(static fn(string $column): string => "$column LIKE ?", $search)) . ')';
            array_push($parameters, ...array_fill(0, count($search), '%' . $filters['filter'] . '%'));
        }
        return $this->database->fetchAllNumeric($select . $from . " WHERE $where ORDER BY $order LIMIT " . ($rows + 1) . " OFFSET $offset", $parameters);
    }

    private function scope(int $actorId): string
    {
        // Match get_allowed_management_device_ids_sql(): no graph-view hide-
        // disabled/deleted filter, and retain its simple device-permission shortcut.
        $policies = $this->database->fetchAllAssociative(DeviceVisibilityRules::POLICIES, [$actorId, $actorId]);
        $user = array_find($policies, static fn(array $policy): bool => $policy['kind'] === 'user');
        if ($user === null) {
            throw new \RuntimeException('Management actor is unavailable.');
        }
        $simple = (int) $user['policy_hosts'] === 1
            && (int) $this->database->fetchOne('SELECT COUNT(*) FROM user_auth_perms WHERE user_id = ? AND type = 3', [$actorId]) === 0;
        $predicate = $simple ? '1 = 1' : DeviceVisibilityRules::predicate(
            DeviceVisibilityRules::mode($this->database->fetchOne(DeviceVisibilityRules::MODE)),
            $policies,
        );
        return "SELECT DISTINCT h.id FROM host h LEFT JOIN graph_local gl ON gl.host_id = h.id WHERE h.id > 0 AND ($predicate)";
    }
}
