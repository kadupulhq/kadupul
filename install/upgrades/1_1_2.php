<?php
/*
 * SPDX-FileCopyrightText: 2004-2026 The Cacti Group
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

function upgrade_to_1_1_2() {
	db_install_drop_key('graph_templates_item', 'key', 'local_graph_id');
	db_install_add_key('graph_templates_item', 'index', 'local_graph_id_sequence', array('local_graph_id', 'sequence'));

	db_install_drop_key('graph_tree_items', 'index', 'parent');
	db_install_add_key('graph_tree_items', 'index', 'parent_position', array('parent', 'position'));

	db_install_add_key('graph_tree', 'index', 'sequence', array('sequence'));

	db_install_execute('ALTER TABLE `graph_template_input_defs`
		COMMENT = \'Stores the relationship for what graph items are associated\';');

	db_install_execute('UPDATE graph_templates_item SET hash="" WHERE local_graph_id>0');
}
