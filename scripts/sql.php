<?php

/*
 * SPDX-FileCopyrightText: 2004-2026 The Cacti Group
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

error_reporting(0);

include(dirname(__FILE__) . '/../include/cli_check.php');

global $database_hostname, $database_username, $database_password;

// Credentials go in a private option file, not the process arguments.
$option_file = db_client_option_file($database_username, $database_password);

if ($option_file === false) {
    print 'U';

    exit;
}

$args = array(
    '--defaults-extra-file=' . $option_file,
    '--host=' . $database_hostname,
    'status'
);

try {
    $output = cacti_exec_string('mysqladmin', $args);
} finally {
    unlink($option_file);
}

if ($output === null || $output === '') {
    print 'U';
} else {
    // Extract the 6th field (Queries per second avg), matching original awk '{print $6}'
    $parts = preg_split('/\s+/', trim($output));
    print isset($parts[5]) ? $parts[5] : 'U';
}
