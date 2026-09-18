<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

if (PHP_OS_FAMILY !== 'Windows') {
    throw new RuntimeException('This regression requires native Windows filesystem attributes.');
}
$directory = getenv('WINDOWS_STORAGE_TEST_ROOT');
if (!$directory || !is_dir($directory)) {
    throw new RuntimeException('The Windows fixture directory is missing.');
}
$config = array('cacti_server_os' => 'win32', 'rra_path' => $directory);
function read_config_option($key)
{
    return false;
}
function __($message)
{
    return $message;
}
require dirname(__DIR__, 2) . '/lib/rrd_maintenance.php';
$before = scandir($directory);
$error = rrd_maintenance_configuration_error();
if ($error !== '' || scandir($directory) !== $before) {
    throw new RuntimeException('Writable directory with read-only attribute was rejected or probe leaked: ' . $error);
}
$config['rra_path'] .= '/missing';
if (rrd_maintenance_configuration_error() === '') {
    throw new RuntimeException('Missing Windows storage was accepted.');
}
echo "Windows directory attributes do not override actual read/write/delete access.\n";
