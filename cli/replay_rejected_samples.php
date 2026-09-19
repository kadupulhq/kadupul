#!/usr/bin/env php
<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

require(__DIR__ . '/../include/cli_check.php');
require_once($config['base_path'] . '/lib/poller.php');
require_once __DIR__ . '/../lib/rrd_maintenance.php';

/* process calling arguments */
$parms = $_SERVER['argv'];
array_shift($parms);

$local_data_id = null;
$all           = false;
$dry_run       = false;

foreach ($parms as $parameter) {
    if (strpos($parameter, '=')) {
        list($arg, $value) = explode('=', $parameter, 2);
    } else {
        $arg   = $parameter;
        $value = '';
    }

    switch ($arg) {
        case '--local-data-id':
            if (!ctype_digit($value) || (int) $value < 1) {
                print 'ERROR: --local-data-id requires a positive integer' . PHP_EOL . PHP_EOL;
                display_help();
                exit(1);
            }
            $local_data_id = (int) $value;
            break;
        case '--all':
            $all = true;
            break;
        case '--dry-run':
            $dry_run = true;
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

/* Replaying every data source must be asked for explicitly. */
if ($all === ($local_data_id !== null)) {
    print 'ERROR: Specify exactly one of --local-data-id=N or --all' . PHP_EOL . PHP_EOL;
    display_help();
    exit(1);
}

/* An online remote collector queues into the main database, where the drain also dead-letters,
 * so this collector's local tables hold neither the rejected samples nor the live queue. */
if (($config['poller_id'] ?? 1) > 1 && ($config['connection'] ?? '') === 'online') {
    fwrite(STDERR, 'ERROR: This collector queues samples in the main database. Run replay_rejected_samples.php on the main data collector.' . PHP_EOL);
    exit(1);
}

/* Replay writes to poller_output on this connection; a MEMORY queue would lose them on restart. */
if (!$dry_run) {
    $queue_error = rrd_maintenance_queue_configuration_error(false);
    if ($queue_error !== '') {
        fwrite(STDERR, 'ERROR: Rejected samples were not replayed. ' . $queue_error . PHP_EOL);
        exit(1);
    }
}

$replayed = poller_replay_rejected($local_data_id, $dry_run);

if ($replayed === false) {
    fwrite(STDERR, 'ERROR: Unable to replay rejected samples; nothing was moved.' . PHP_EOL);
    exit(1);
}

$scope   = $local_data_id === null ? 'all data sources' : 'Local Data ID ' . $local_data_id;
$message = ($dry_run ? 'Would replay ' : 'Replayed ') . $replayed . ' rejected samples for ' . $scope;

if (!$dry_run) {
    cacti_log('NOTE: ' . $message . ' into poller_output.', false, 'POLLER');
}

print $message . PHP_EOL;
exit(0);

/* display_version - displays version information */
function display_version()
{
    $version = get_cacti_cli_version();
    print 'Kadupul Rejected Sample Replay Utility, Version ' . $version . ', ' . COPYRIGHT_YEARS . PHP_EOL;
}

/* display_help - displays the usage of the function */
function display_help()
{
    display_version();

    print PHP_EOL . 'usage: replay_rejected_samples.php --local-data-id=N | --all [--dry-run]' . PHP_EOL . PHP_EOL;
    print 'Returns samples from poller_output_rejected to poller_output for the next' . PHP_EOL;
    print 'poller drain.  Repair the RRDfile first or the samples will be rejected again.' . PHP_EOL . PHP_EOL;
    print '--local-data-id=N  Replay one data source' . PHP_EOL;
    print '--all              Replay every data source' . PHP_EOL;
    print '--dry-run          Report the count without moving anything' . PHP_EOL . PHP_EOL;
}
