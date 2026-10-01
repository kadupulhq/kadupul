<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

function upgrade_to_1_2_32(): void
{
    if (!db_index_exists('data_template_rrd', 'data_input_field_id')) {
        db_install_execute('ALTER TABLE data_template_rrd ADD INDEX data_input_field_id (data_input_field_id)');
    }
}
