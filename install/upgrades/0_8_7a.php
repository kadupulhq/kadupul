<?php
/*
 * SPDX-FileCopyrightText: 2004-2026 The Cacti Group
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

function upgrade_to_0_8_7a() {
	/* add alpha channel to graph items */
	db_install_add_column('graph_templates_item', array('name' => 'alpha', 'type' => 'char(2)', 'NULL' => false, 'after' => 'color_id', 'default' => 'FF'));

	/* add units=si as an option */
	db_install_add_column('graph_templates_graph', array('name' => 't_scale_log_units', 'type' => 'char(2)', 'NULL' => false, 'after' => 'auto_scale_log', 'default' => 0));
	db_install_add_column('graph_templates_graph', array('name' => 'scale_log_units', 'type' => 'char(2)', 'NULL' => false, 'after' => 't_scale_log_units', 'default' => ''));
}
