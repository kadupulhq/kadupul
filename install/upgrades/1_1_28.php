<?php
/*
 * SPDX-FileCopyrightText: 2004-2026 The Cacti Group
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

function upgrade_to_1_1_28() {
	db_install_add_key('poller', 'index', 'disabled', array('disabled'));
	db_install_drop_key('host', 'key', 'poller_id');
	db_install_add_key('host', 'index', 'poller_id_disabled', array('poller_id', 'disabled'));
	db_install_drop_key('poller_item', 'index', 'local_data_id');
	db_install_add_key('poller_item', 'index', 'poller_id_action', array('poller_id', 'action'));
}
