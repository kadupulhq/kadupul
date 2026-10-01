#!/usr/bin/env php
<?php

/*
 * SPDX-FileCopyrightText: 2004-2026 The Cacti Group
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

require(__DIR__ . '/../include/cli_check.php');
require_once(__DIR__ . '/../lib/input_whitelist.php');
require_once($config['base_path'] . '/lib/utility.php');
require_once($config['base_path'] . '/lib/poller.php');
require_once($config['base_path'] . '/lib/template.php');

if ($config['poller_id'] > 1) {
    print "FATAL: This utility is designed for the main Data Collector only" . PHP_EOL;
    exit(1);
}

$audit  = false;
$update = false;
$push   = false;
$id     = false;

// process calling arguments
$parms = $_SERVER['argv'];
array_shift($parms);

if (cacti_sizeof($parms)) {
    foreach ($parms as $parameter) {
        if (strpos($parameter, '=')) {
            list($arg, $value) = explode('=', $parameter, 2);
        } else {
            $arg = $parameter;
            $value = '';
        }

        switch ($arg) {
            case '--id':
            case '-I':
                $id = intval($value);
                break;
            case '--audit':
            case '-A':
                $audit = true;
                break;
            case '--update':
            case '-U':
                $update = true;
                break;
            case '--push':
            case '-P':
                $push = true;
                break;
            case '--version':
            case '-V':
            case '-v':
                display_version();
                exit(0);
            case '--help':
            case '-H':
            case '-h':
                display_help();
                exit(0);
            default:
                print 'ERROR: Invalid Parameter ' . $parameter . PHP_EOL . PHP_EOL;
                display_help();
                exit(1);
        }
    }
}

if (!isset($config['input_whitelist'])) {
    print 'NOTICE: Data Input Whitelist file not defined in config.php.' . PHP_EOL;
    exit(0);
}

if ($audit) {
    if (isset($config['input_whitelist']) && !file_exists($config['input_whitelist'])) {
        print 'ERROR: Data Input Whitelist file \'' . $config['input_whitelist'] . '\' does not exist.  Please run with the \'--update\' option.' . PHP_EOL;
        exit(1);
    }

    $input = json_decode(file_get_contents($config['input_whitelist']), true);

    $totals = 0;
    $items = cacti_sizeof($input);

    if ($items) {
        print 'Data Input Methods Whitelist Verification' . PHP_EOL . PHP_EOL;
        print '------------------------------------------------------------------------------------------------------------' . PHP_EOL;

        foreach ($input as $hash => $input_string) {
            $aud = verify_data_input($hash, $input_string);
            if ($aud['status'] == true) {
                print 'ID: ' . $aud['id'] . ', Name: ' . $aud['name'] . ', Status: ' . 'Success' . PHP_EOL;
                print '------------------------------------------------------------------------------------------------------------' . PHP_EOL;
                print 'Command:   ' . $aud['input_string'] . PHP_EOL . 'Whitelist: ' . $input_string . PHP_EOL;
            } else {
                print 'ID: ' . $aud['id'] . ', Name: ' . $aud['name'] . ', Status: ' . 'Failed' . PHP_EOL;
                print '------------------------------------------------------------------------------------------------------------' . PHP_EOL;
                print 'Command:   ' . $aud['input_string'] . PHP_EOL . 'Whitelist: ' . $input_string . PHP_EOL;

                $totals++;
            }

            print '------------------------------------------------------------------------------------------------------------' . PHP_EOL . PHP_EOL;
        }

        if ($totals) {
            print 'ERROR: ' . $totals . ' audits failed out of a total of ' . $items . ' Data Input Methods' . PHP_EOL;
        } else {
            print 'SUCCESS: Audits successful for total of ' . $items . ' Data Input Methods' . PHP_EOL;
        }
    }
} elseif ($update) {
    if (!is_writable(dirname($config['input_whitelist']))) {
        print 'ERROR: Data Input whitelist file \'' . $config['input_whitelist'] . '\' not writeable.' . PHP_EOL;
        exit(1);
    }

    if ($id !== false && $id <= 0) {
        print 'ERROR: Data Input id \'' . $id . '\' is invalid. Please provide a positive integer.' . PHP_EOL;
        exit(1);
    }
    $empty = $missing = false;
    try {
        // The leaf is supervised for at most 30 seconds; leave time to return
        // its outcome. The lock covers local snapshot/replacement, never collector I/O.
        $pushes = data_input_whitelist_update($config['input_whitelist'], static function () use ($id, &$empty, &$missing): array {
            $id_hash = false;
            if ($id !== false) {
                $id_hash = db_fetch_cell_prepared('SELECT hash FROM data_input WHERE id = ?', [$id]);
                if (empty($id_hash)) {
                    $missing = true;
                    throw new RuntimeException('Data Input id \'' . $id . '\' was not found or has an empty hash.');
                }
            }
            $input_db = db_fetch_assoc('SELECT id, name, hash, input_string
                FROM data_input
                WHERE input_string != ""');
            if (!cacti_sizeof($input_db)) {
                $empty = true;
                throw new RuntimeException('No Data Input records found.');
            }
            return [$input_db, $id_hash];
        }, $push, hrtime(true) / 1e9 + 25);
    } catch (Throwable $error) {
        print ($empty ? 'ERROR: No Data Input records found.' : ($missing ? 'ERROR: ' : 'ERROR: Data Input Whitelist update failed: ') . $error->getMessage()) . PHP_EOL;
        exit(1);
    }
    print 'SUCCESS: Data Input Whitelist file \'' . $config['input_whitelist'] . '\' successfully updated.' . PHP_EOL;

    if (cacti_sizeof($pushes)) {
        foreach ($pushes as $data_input_method => $name) {
            print 'NOTE: Pushing Out Data Input Method: ' . $name . ' (' . $data_input_method . ')' . PHP_EOL;
            push_out_data_input_method($data_input_method);
        }
    }
} else {
    display_help();
}

exit(0);

/*
 * display_version - displays version information
 */
function display_version()
{
    $version = get_cacti_cli_version();
    print "Kadupul Data Input Whitelist Utility, Version $version, " . COPYRIGHT_YEARS . PHP_EOL;
}

/*
 * display_help - displays the usage of the function
 */
function display_help()
{
    display_version();

    print PHP_EOL . "usage: input_whitelist.php [--audit | --update [--id=N] [--push]]" . PHP_EOL . PHP_EOL;

    print "A utility audit and update the Data Input whitelist status and" . PHP_EOL;
    print "Data Input protection file." . PHP_EOL . PHP_EOL;

    print "Optional:" . PHP_EOL;
    print "    --id=N        Audit or update only the Data Input Method id specified." . PHP_EOL;
    print "    --audit       Audit but do not update the whitelist file." . PHP_EOL;
    print "    --update      Update the whitelist file with latest information." . PHP_EOL;
    print "    --push        If any input strings are being updated to new values," . PHP_EOL;
    print "                  push out the Data Input Methods with new input strings." . PHP_EOL . PHP_EOL;
}
