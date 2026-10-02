<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

function upgrade_to_1_2_33(): void
{
    // Match user_auth.id so credential metadata can represent every account.
    db_install_execute("ALTER TABLE settings_user MODIFY user_id mediumint(8) unsigned NOT NULL default '0'");
    if (!db_index_exists('data_template_rrd', 'data_input_field_id')) {
        db_install_execute('ALTER TABLE data_template_rrd ADD INDEX data_input_field_id (data_input_field_id)');
    }
}
