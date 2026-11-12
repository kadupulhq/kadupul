<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Automation\Domain;

/** An automation_networks row as the General form shows it. */
final readonly class DiscoveryNetwork
{
    /** The columns an edit can change; run state written by the poller is left out so a running discovery does not make the form stale. */
    public const array REVISION_COLUMNS = ['id', 'name', 'poller_id', 'site_id', 'subnet_range', 'dns_servers', 'enabled', 'notification_enabled', 'notification_email',
        'notification_fromname', 'notification_fromemail', 'snmp_id', 'enable_netbios', 'add_to_cacti', 'same_sysname', 'ping_method', 'ping_port', 'ping_timeout',
        'ping_retries', 'sched_type', 'threads', 'run_limit', 'start_at', 'recur_every', 'day_of_week', 'month', 'day_of_month', 'monthly_week', 'monthly_day', 'rerun_data_queries'];

    /** @param array<string, bool|int|string|list<int>> $settings the form's initial values */
    public function __construct(public int $id, public string $name, public int $totalIps, public array $settings, public string $revision) {}

    /** @param array<string, mixed> $row */
    public static function revisionOf(array $row): string
    {
        $values = [];
        foreach (self::REVISION_COLUMNS as $column) {
            $value = $row[$column] ?? null;
            $values[] = $value === null ? null : (is_scalar($value) ? (string) $value : '');
        }
        return hash('sha256', json_encode($values, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
    }
}
