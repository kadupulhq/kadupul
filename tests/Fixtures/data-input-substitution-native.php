<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

/*
 * Loaded after include/global_constants.php and lib/functions.php in a child
 * PHP process. The database is the only stubbed boundary: the production
 * get_full_script_path() and get_full_test_script_path() build the command,
 * and /bin/sh parses it.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

function db_fetch_row_prepared($sql, $params = array(), $log = true, $db_conn = false)
{
    if (str_contains($sql, 'FROM host WHERE')) {
        return $GLOBALS['data_input_fixture']['host'];
    }

    return array(
        'id'            => 7,
        'data_input_id' => 3,
        'type_id'       => DATA_INPUT_TYPE_SCRIPT,
        'input_string'  => $GLOBALS['data_input_fixture']['template'],
    );
}

function db_fetch_assoc_prepared($sql, $params = array(), $log = true, $db_conn = false)
{
    $rows = array();

    foreach ($GLOBALS['data_input_fixture']['fields'] as $name => $value) {
        $rows[] = array('data_name' => (string) $name, 'value' => $value);
    }

    return $rows;
}

/**
 * @param array<string, string|null> $fields
 * @param array<string, mixed>       $host
 */
function data_input_command(string $caller, string $template, array $fields, array $host = array()): string
{
    $GLOBALS['data_input_fixture'] = array('template' => $template, 'fields' => $fields, 'host' => $host);

    if ($caller === 'poller') {
        return (string) get_full_script_path(7);
    }

    return (string) get_full_test_script_path(5, 9);
}

/**
 * Lets /bin/sh parse the command as the poller would, but prints each word
 * instead of running the script.
 *
 * @return array{status: int, words: list<string>, err: string}
 */
function data_input_shell_words(string $command): array
{
    $pipes   = array();
    $process = proc_open(array('/bin/sh', '-c', 'printf "%s\\0" ' . $command), array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);

    if (!is_resource($process)) {
        throw new RuntimeException('Cannot start /bin/sh');
    }

    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $status = proc_close($process);
    $words  = $out === '' ? array() : explode("\0", substr($out, 0, -1));

    return array('status' => $status, 'words' => $words, 'err' => $err);
}
