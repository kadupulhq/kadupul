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
    /**
     * @param array<string, mixed> $host
     * @return array{0: list<string>, 1: list<mixed>}
     */
    public static function replacements(mixed $l_escape_string, mixed $r_escape_string, array $host): array
    {
        $search  = array();
        $replace = array();

        $search[]  = $l_escape_string . 'host_management_ip' . $r_escape_string; /* for compatibility */
        $replace[] = $host['hostname']; /* for compatibility */

        /* common host columns */
        $search[]  = $l_escape_string . 'host_id' . $r_escape_string;
        $replace[] = $host['id'];
        $search[]  = $l_escape_string . 'host_hostname' . $r_escape_string;
        $replace[] = $host['hostname'];
        $search[]  = $l_escape_string . 'host_description' . $r_escape_string;
        $replace[] = $host['description'];
        $search[]  = $l_escape_string . 'host_site' . $r_escape_string;
        $replace[] = $host['site_name'];
        $search[]  = $l_escape_string . 'host_notes' . $r_escape_string;
        $replace[] = $host['notes'];
        $search[]  = $l_escape_string . 'host_location' . $r_escape_string;
        $replace[] = $host['location'];
        $search[]  = $l_escape_string . 'host_polling_time' . $r_escape_string;
        $replace[] = $host['polling_time'];
        $search[]  = $l_escape_string . 'host_avg_time' . $r_escape_string;
        $replace[] = $host['avg_time'];
        $search[]  = $l_escape_string . 'host_cur_time' . $r_escape_string;
        $replace[] = $host['cur_time'];
        $search[]  = $l_escape_string . 'host_availability' . $r_escape_string;
        $replace[] = $host['availability'];
        $search[]  = $l_escape_string . 'host_uptime' . $r_escape_string;
        $replace[] = \get_uptime($host);

        /* snmp connectivity information */
        $search[]  = $l_escape_string . 'host_snmp_community' . $r_escape_string;
        $replace[] = $host['snmp_community'];
        $search[]  = $l_escape_string . 'host_snmp_version' . $r_escape_string;
        $replace[] = $host['snmp_version'];
        $search[]  = $l_escape_string . 'host_snmp_username' . $r_escape_string;
        $replace[] = $host['snmp_username'];
        $search[]  = $l_escape_string . 'host_snmp_password' . $r_escape_string;
        $replace[] = $host['snmp_password'];
        $search[]  = $l_escape_string . 'host_snmp_auth_protocol' . $r_escape_string;
        $replace[] = $host['snmp_auth_protocol'];
        $search[]  = $l_escape_string . 'host_snmp_priv_passphrase' . $r_escape_string;
        $replace[] = $host['snmp_priv_passphrase'];
        $search[]  = $l_escape_string . 'host_snmp_priv_protocol' . $r_escape_string;
        $replace[] = $host['snmp_priv_protocol'];
        $search[]  = $l_escape_string . 'host_snmp_context' . $r_escape_string;
        $replace[] = $host['snmp_context'];
        $search[]  = $l_escape_string . 'host_snmp_engine_id' . $r_escape_string;
        $replace[] = $host['snmp_engine_id'];
        $search[]  = $l_escape_string . 'host_snmp_port' . $r_escape_string;
        $replace[] = $host['snmp_port'];
        $search[]  = $l_escape_string . 'host_snmp_timeout' . $r_escape_string;
        $replace[] = $host['snmp_timeout'];

        /* snmp system information */
        $search[]  = $l_escape_string . 'host_snmp_sysDescr' . $r_escape_string;
        $replace[] = $host['snmp_sysDescr'];
        $search[]  = $l_escape_string . 'host_snmp_sysObjectID' . $r_escape_string;
        $replace[] = $host['snmp_sysObjectID'];
        $search[]  = $l_escape_string . 'host_snmp_sysContact' . $r_escape_string;
        $replace[] = $host['snmp_sysContact'];
        $search[]  = $l_escape_string . 'host_snmp_sysLocation' . $r_escape_string;
        $replace[] = $host['snmp_sysLocation'];
        $search[]  = $l_escape_string . 'host_snmp_sysName' . $r_escape_string;
        $replace[] = $host['snmp_sysName'];
        $search[]  = $l_escape_string . 'host_snmp_sysUpTimeInstance' . $r_escape_string;
        $replace[] = $host['snmp_sysUpTimeInstance'];

        $search[]  = $l_escape_string . 'host_ping_retries' . $r_escape_string;
        $replace[] = $host['ping_retries'];
        $search[]  = $l_escape_string . 'host_max_oids' . $r_escape_string;
        $replace[] = $host['max_oids'];

        /* handle the external id */
        $search[]  = $l_escape_string . 'host_external_id' . $r_escape_string;
        $replace[] = $host['external_id'];

        return [$search, $replace];
    }
}
