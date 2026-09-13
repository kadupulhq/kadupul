<?php
/*
 * SPDX-FileCopyrightText: 2004-2026 The Cacti Group
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

function upgrade_to_0_8_7b() {
	/* add Task Item Id Index */
	db_install_add_key('graph_templates_item', 'index', 'task_item_id', array('task_item_id'));

	/* make CLI more responsive */
	db_install_add_key('data_input_data', 'index', 't_value', array('t_value'));
}
