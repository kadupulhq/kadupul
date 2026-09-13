<?php
/*
 * SPDX-FileCopyrightText: 2004-2026 The Cacti Group
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

function upgrade_to_0_8_2a() {
	db_install_add_column('data_input_data_cache', array('name' => 'rrd_num', 'type' => 'tinyint(2)', 'NULL' => false, 'after' => 'rrd_path'));
}
