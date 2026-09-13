<?php
/*
 * SPDX-FileCopyrightText: 2004-2026 The Cacti Group
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

function upgrade_to_1_2_7() {
	db_install_add_key('data_input_data', 'index', 'data_template_data_id', array('data_template_data_id'));
}
