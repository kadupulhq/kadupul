<?php

/*
 * SPDX-FileCopyrightText: 2004-2026 The Cacti Group
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

function upgrade_to_1_2_31()
{
    global $config;

    db_install_execute('ALTER TABLE automation_devices MODIFY COLUMN snmp_priv_protocol char(7) default ""');
    db_install_execute('ALTER TABLE automation_snmp_items MODIFY COLUMN snmp_priv_protocol char(7) default ""');
    db_install_execute('ALTER TABLE snmpagent_managers MODIFY COLUMN snmp_priv_protocol char(7) NOT NULL');

    db_install_execute('ALTER TABLE settings MODIFY COLUMN `name` varchar(255) NOT NULL default ""');

    if (!db_index_exists('snmp_query_graph', 'snmp_query_id')) {
        db_install_execute('ALTER TABLE snmp_query_graph ADD INDEX snmp_query_id (snmp_query_id)');
    }

    if (!db_index_exists('snmp_query_graph', 'graph_template_id')) {
        db_install_execute('ALTER TABLE snmp_query_graph ADD INDEX graph_template_id (graph_template_id)');
    }

    db_install_execute('ALTER TABLE settings_user MODIFY COLUMN name varchar(255) NOT NULL default ""');

    if (!db_index_exists('user_auth_row_cache', 'class_time')) {
        db_install_execute('ALTER TABLE user_auth_row_cache ADD INDEX class_time (class, time)');
    }

    /* Samples RRDtool keeps refusing are moved here instead of growing the queue. */
    db_install_execute('CREATE TABLE IF NOT EXISTS poller_output_rejected (
		local_data_id int(10) unsigned NOT NULL default "0",
		rrd_name varchar(19) NOT NULL default "",
		time timestamp NOT NULL default CURRENT_TIMESTAMP,
		output varchar(512) NOT NULL default "",
		rrd_path varchar(255) NOT NULL default "",
		reason varchar(255) NOT NULL default "",
		first_rejected timestamp NOT NULL default CURRENT_TIMESTAMP,
		last_rejected timestamp NOT NULL default CURRENT_TIMESTAMP,
		PRIMARY KEY (local_data_id, rrd_name, time),
		KEY rrd_path (rrd_path))
		ENGINE=InnoDB ROW_FORMAT=Dynamic');

    upgrade_ldap_tls_requirement();
}

/**
 * upgrade_ldap_tls_requirement - keep the certificate check an install already
 *   ran with.
 *
 * The TLS certificate requirement now defaults to Demand. An install that uses
 * LDAPS or StartTLS without a saved requirement never checked the directory's
 * certificate, and switching it to Demand would fail every LDAP login until an
 * administrator adds the CA. Those installs get Never saved explicitly, which
 * they can raise in the settings. Installs that saved a value keep it, and
 * installs without LDAP encryption get the new default.
 *
 * @return (void)
 */
function upgrade_ldap_tls_requirement()
{
    $saved = db_install_fetch_cell('SELECT value FROM settings WHERE name = ?', array('ldap_tls_certificate'));

    if ($saved['data'] !== false && $saved['data'] !== null && $saved['data'] !== '') {
        return;
    }

    $global = db_install_fetch_cell('SELECT value FROM settings WHERE name = ?', array('ldap_encryption'));
    $domains = db_install_fetch_cell('SELECT COUNT(*) FROM user_domains_ldap WHERE encryption > 0');

    if ((int) $global['data'] > 0 || (int) $domains['data'] > 0) {
        db_install_execute('REPLACE INTO settings (name, value) VALUES (?, ?)', array('ldap_tls_certificate', (string) LDAP_OPT_X_TLS_NEVER));
    }
}
