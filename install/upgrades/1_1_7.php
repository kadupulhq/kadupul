<?php
/*
 * SPDX-FileCopyrightText: 2004-2026 The Cacti Group
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

function upgrade_to_1_1_7() {
	db_install_execute('ALTER TABLE poller_data_template_field_mappings
		MODIFY COLUMN data_name VARCHAR(40) NOT NULL default ""');
}
