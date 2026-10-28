#!/usr/bin/env php
<?php

/*
 * SPDX-FileCopyrightText: 2004-2026 The Cacti Group
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

error_reporting(0);

if (!isset($called_by_script_server)) {
    include_once(dirname(__FILE__) . '/../include/cli_check.php');

    print call_user_func('ss_sql');
}

function ss_sql()
{
    global $database_username;
    global $database_password;
    global $database_hostname;

    // Credentials go in a private option file, not the process arguments.
    $option_file = db_client_option_file($database_username, $database_password);

    if ($option_file === false) {
        return 'U';
    }

    $args = array(
        '--defaults-extra-file=' . $option_file,
        '--host=' . $database_hostname,
        'status'
    );

    try {
        $result = cacti_exec_string('mysqladmin', $args);
    } finally {
        unlink($option_file);
    }

    $result = preg_replace('/: /', ':', $result);
    $result = preg_replace('/  /', ' ', $result);
    $result = preg_replace('/Slow queries/', 'SlowQueries', $result);
    $result = preg_replace('/Open tables/', 'OpenTables', $result);
    $result = preg_replace('/Queries per second avg/', 'QPS', $result);
    $result = preg_replace('/Flush tables/', 'FlushTables', $result);

    return trim($result) ?: 'U';
}
