<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later
$config = array('base_path' => getenv('SNMP_NATIVE_DIRECTORY'));
$db = new PDO('sqlite:' . $config['base_path'] . '/cache.sqlite', null, null, array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
function db_fetch_assoc($sql)
{
    return $GLOBALS['db']->query($sql)->fetchAll(PDO::FETCH_ASSOC);
}
function db_fetch_cell_prepared($sql, $params)
{
    if (!str_contains($sql, 'SHOW TABLE STATUS') || count($params) !== 1 || !is_int($params[0])) {
        throw new RuntimeException('Unexpected cache modification query');
    }
    return 1;
}
function cacti_sizeof($value)
{
    return is_array($value) ? count($value) : 0;
}
function read_config_option($key)
{
    if ($key !== 'path_php_binary') {
        throw new RuntimeException('Unexpected setting');
    } return PHP_BINARY;
}
function cacti_escapeshellcmd($value)
{
    return escapeshellcmd($value);
}
function cacti_escapeshellarg($value)
{
    return escapeshellarg($value);
}
