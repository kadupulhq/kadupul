<?php
/*
 * SPDX-FileCopyrightText: 2004-2026 The Cacti Group
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

function upgrade_to_1_1_14() {
	db_install_execute('ALTER TABLE automation_networks
		MODIFY COLUMN subnet_range VARCHAR(1024) NOT NULL DEFAULT ""');
}
