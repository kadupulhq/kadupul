<?php
/*
 * SPDX-FileCopyrightText: 2004-2026 The Cacti Group
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

function upgrade_to_1_0_4() {
	db_install_add_key('poller_output_boost', 'KEY', 'PRIMARY', array('local_data_id', 'time', 'rrd_name'), 'BTREE');
}
