<?php
/*
 * SPDX-FileCopyrightText: 2004-2026 The Cacti Group
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

function upgrade_to_1_1_37() {
	db_install_execute('ALTER TABLE host MODIFY COLUMN snmp_sysObjectID varchar(128) NOT NULL DEFAULT ""');
}

