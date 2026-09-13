<?php
/*
 * SPDX-FileCopyrightText: 2004-2026 The Cacti Group
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

function upgrade_to_1_2_3() {
	// Correct max values in templates and data sources: GAUGE/ABSOLUTE (1,4)
	db_install_execute("UPDATE data_template_rrd
		SET rrd_maximum='U'
		WHERE rrd_maximum = '0'
		AND rrd_minimum = '0'
		AND data_source_type_id IN(1,4)");

	// Correct min/max values in templates and data sources: DERIVE/DDERIVE (3,7)
	db_install_execute("UPDATE data_template_rrd
		SET rrd_maximum='U', rrd_minimum='U'
		WHERE (rrd_maximum = '0' OR rrd_minimum = '0')
		AND data_source_type_id IN(3,7)");

	// Speed up Data Sources page
	db_install_add_key('data_template_data', 'key', 'name_cache', array('name_cache(191)'));
}
