<?php
/*
 * SPDX-FileCopyrightText: 2004-2026 The Cacti Group
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

function upgrade_to_1_2_25() {
	db_install_execute("ALTER TABLE `settings` MODIFY `name` varchar(75) not null default ''");
	db_install_execute("ALTER TABLE `settings_user` MODIFY `name` varchar(75) not null default ''");
}

