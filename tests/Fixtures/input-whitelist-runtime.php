<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

// Execute the actual runtime predicate with owned filesystem and DB adapters.
require $argv[1] . '/lib/utility.php';
$case = $argv[2];
$directory = sys_get_temp_dir() . '/whitelist-runtime-' . bin2hex(random_bytes(8));
mkdir($directory, 0700);
$path = $directory . '/allow.json';
$hash = str_repeat('1', 32);
$records = array(
    array('id' => 1, 'hash' => $hash, 'name' => 'Owned method', 'input_string' => 'owned command'),
    array('id' => 2, 'hash' => str_repeat('2', 32), 'name' => 'No command', 'input_string' => '')
);
$config = array('input_whitelist' => $path);
$logs = array();
function db_fetch_assoc($sql) { return $GLOBALS['records']; }
function array_rekey($rows, $key, $columns) {
    $result = array();
    foreach ($rows as $row) {
        $result[$row[$key]] = array_intersect_key($row, array_flip($columns));
    }
    return $result;
}
function cacti_sizeof($value) { return is_array($value) ? count($value) : 0; }
function cacti_log($message) { $GLOBALS['logs'][] = $message; }
$contents = array(
    'valid' => json_encode(array($hash => 'owned command')),
    'malformed' => '{broken',
    'null' => 'null',
    'boolean' => 'true',
    'scalar' => '17',
    'empty' => '{}',
    'missing-entry' => json_encode(array(str_repeat('3', 32) => 'owned command')),
    'changed-command' => json_encode(array($hash => 'different command')),
    'wrong-type' => json_encode(array($hash => true))
);
try {
    if ($case === 'unset') {
        unset($config['input_whitelist']);
    } elseif ($case === 'invalid-path') {
        $config['input_whitelist'] = array();
    } elseif ($case === 'directory') {
        $config['input_whitelist'] = $directory;
    } elseif ($case !== 'missing-file') {
        if (!isset($contents[$case])) {
            throw new RuntimeException('Unknown fixture case');
        }
        file_put_contents($path, $contents[$case]);
    }
    set_error_handler(function ($severity, $message) {
        if (error_reporting() & $severity) {
            throw new ErrorException($message, 0, $severity);
        }
    });
    echo json_encode(array(
        'first' => data_input_whitelist_check(1),
        'repeat' => data_input_whitelist_check(1),
        'empty-command' => data_input_whitelist_check(2),
        'unknown' => data_input_whitelist_check(999)
    ), JSON_THROW_ON_ERROR);
} finally {
    if (is_file($path)) {
        unlink($path);
    }
    rmdir($directory);
}
