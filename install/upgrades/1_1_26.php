<?php
/*
 * SPDX-FileCopyrightText: 2004-2026 The Cacti Group
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

function upgrade_to_1_1_26() {
	db_install_add_key('host', 'key', 'status', array('status'));
	db_install_add_key('user_auth_cache', 'key', 'last_update', array('last_update'));
	db_install_add_key('poller_output_realtime', 'key', 'time', array('time'));
	db_install_add_key('poller_time', 'key', 'poller_id_end_time', array('poller_id', 'end_time'));

	if (db_column_exists('poller_item', 'rrd_next_step')) {
		db_install_add_key('poller_item', 'key', 'poller_id_rrd_next_step', array('poller_id', 'rrd_next_step'));
	}

	if (db_column_exists('poller_item', 'rrd_next_step')) {
		db_install_drop_key('poller_item', 'key', 'rrd_next_step');
	}
}
