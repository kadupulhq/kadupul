<?php
/*
 * SPDX-FileCopyrightText: 2004-2026 The Cacti Group
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

function upgrade_to_1_2_1() {
	db_install_execute("UPDATE host SET deleted='' WHERE deleted IS NULL");
	db_install_execute("ALTER TABLE host MODIFY COLUMN deleted char(3) NOT NULL default ''");
}
