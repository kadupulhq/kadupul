<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2004-2026 The Cacti Group
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace Kadupul\Platform\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Kadupul\Navigation\Contract\AdministrationCatalog;
use Kadupul\Navigation\Contract\Administration\ListFilters;
use Kadupul\Navigation\Contract\Administration\ListScreen;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('kadupul.administration_catalog')]
final readonly class DbalPlatformAdministrationCatalog implements AdministrationCatalog
{
    public function __construct(
        #[Autowire(service: 'doctrine.dbal.web_connection')]
        private Connection $database,
    ) {}

    public function supports(ListScreen $screen): bool
    {
        return in_array($screen, [ListScreen::NotificationReceivers, ListScreen::Collectors, ListScreen::RrdCandidates, ListScreen::SnmpObjects, ListScreen::SnmpNotifications], true);
    }

    public function read(ListScreen $screen, ListFilters $filters): array
    {
        $source = match ($screen) {
            ListScreen::Collectors => <<<'SQL'
SELECT p.id, p.name, p.hostname,
 CASE WHEN p.disabled = 'on' THEN 4 WHEN UNIX_TIMESTAMP() - UNIX_TIMESTAMP(p.last_status) > 310 THEN 6 ELSE p.status END AS collector_status,
 p.processes, p.threads, p.total_time, p.avg_time, p.max_time,
 COALESCE(h.hosts, 0) AS hosts, p.snmp, p.script, p.server, p.last_update, p.last_status,
 CASE WHEN p.id = 1 THEN NULL ELSE p.last_sync END AS last_sync
 FROM poller p LEFT JOIN (SELECT poller_id, COUNT(*) AS hosts FROM host GROUP BY poller_id) h ON h.poller_id = p.id
SQL,
            ListScreen::RrdCandidates => <<<'SQL'
SELECT p.id, p.name, p.name_cache, p.local_data_id, p.data_template_id,
 t.name AS data_template_name, p.last_mod, ROUND(p.size / 1024, 2) AS size_kib
 FROM data_source_purge_temp p LEFT JOIN data_template t ON t.id = p.data_template_id
 WHERE p.in_cacti = 0
SQL,
            ListScreen::SnmpObjects => <<<'SQL'
SELECT oid AS id, name, mib, kind, `max-access` AS max_access, value AS object_value, description
 FROM snmpagent_cache
SQL,
            ListScreen::SnmpNotifications => <<<'SQL'
SELECT l.id, l.notification AS name, l.time AS event_time, m.hostname, l.severity, l.mib, l.varbinds
 FROM snmpagent_notifications_log l LEFT JOIN snmpagent_managers m ON m.id = l.manager_id
SQL,

            ListScreen::NotificationReceivers => <<<'SQL'
SELECT m.id, m.description AS name, m.hostname, m.disabled,
 COALESCE(n.count_notify, 0) AS count_notify, COALESCE(l.count_log, 0) AS count_log
 FROM snmpagent_managers m
 LEFT JOIN (SELECT manager_id, COUNT(*) AS count_notify FROM snmpagent_managers_notifications GROUP BY manager_id) n ON n.manager_id = m.id
 LEFT JOIN (SELECT manager_id, COUNT(*) AS count_log FROM snmpagent_notifications_log GROUP BY manager_id) l ON l.manager_id = m.id
SQL,
            default => throw new \InvalidArgumentException('Unsupported administration list.'),
        };
        // Only selected public fields leave this adapter. Sorting uses aliases
        // from the fixed screen definition, never request text as an identifier.
        if (!array_key_exists($filters->sortColumn, $screen->columns())) {
            throw new \InvalidArgumentException('Invalid list sort.');
        }
        $search = match ($screen) {
            ListScreen::RrdCandidates => ['name', 'name_cache', 'data_template_name'],
            ListScreen::SnmpObjects => ['id', 'name', 'mib'],
            ListScreen::SnmpNotifications => ['name', 'varbinds'],
            ListScreen::NotificationReceivers => ['name', 'hostname'],
            default => ['name'],
        };
        $where = implode(' OR ', array_map(static fn(string $column): string => 'listing.' . $column . ' LIKE ?', $search));
        $params = array_fill(0, count($search), '%' . $filters->filter . '%');
        $types = array_fill(0, count($search), ParameterType::STRING);
        return $this->database->fetchAllAssociative(
            'SELECT listing.* FROM (' . $source . ') listing WHERE (' . $where . ') ORDER BY listing.'
                . $filters->sortColumn . ' ' . $filters->sortDirection . ', listing.id ASC LIMIT ? OFFSET ?',
            [...$params, $filters->rows + 1, ($filters->page - 1) * $filters->rows],
            [...$types, ParameterType::INTEGER, ParameterType::INTEGER],
        );
    }
}
