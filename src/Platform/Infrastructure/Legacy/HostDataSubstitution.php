<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2004-2026 The Cacti Group
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace Kadupul\Platform\Infrastructure\Legacy;

/** The ordered legacy host substitutions, shared by checked and legacy readers. */
final class HostDataSubstitution
{
    /** Ordered public host fields; uptime is evaluated in its original position. */
    private const FIELDS = [
        'management_ip',
        'id',
        'hostname',
        'description',
        'site',
        'notes',
        'location',
        'polling_time',
        'avg_time',
        'cur_time',
        'availability',
        'uptime',
        'snmp_community',
        'snmp_version',
        'snmp_username',
        'snmp_password',
        'snmp_auth_protocol',
        'snmp_priv_passphrase',
        'snmp_priv_protocol',
        'snmp_context',
        'snmp_engine_id',
        'snmp_port',
        'snmp_timeout',
        'snmp_sysDescr',
        'snmp_sysObjectID',
        'snmp_sysContact',
        'snmp_sysLocation',
        'snmp_sysName',
        'snmp_sysUpTimeInstance',
        'ping_retries',
        'max_oids',
        'external_id',
    ];

    /** Public compatibility names whose database columns differ. */
    private const COLUMN_ALIASES = [
        'management_ip' => 'hostname',
        'site' => 'site_name',
    ];

    /**
     * @param array<string, mixed> $host
     * @return array{0: list<string>, 1: list<mixed>}
     */
    public static function replacements(mixed $l_escape_string, mixed $r_escape_string, array $host): array
    {
        $search = [];
        $replace = [];
        foreach (self::FIELDS as $field) {
            $search[] = $l_escape_string . 'host_' . $field . $r_escape_string;
            $replace[] = $field === 'uptime'
                ? \get_uptime($host)
                : $host[self::COLUMN_ALIASES[$field] ?? $field];
        }

        return [$search, $replace];
    }
}
