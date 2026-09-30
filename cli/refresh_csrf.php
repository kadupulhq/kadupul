#!/usr/bin/env php
<?php

/*
 * SPDX-FileCopyrightText: 2004-2026 The Cacti Group
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

require(__DIR__ . '/../include/cli_check.php');
require_once($config['base_path'] . '/lib/poller.php');
require_once($config['base_path'] . '/lib/utility.php');

/* process calling arguments */
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

/* issue warnings and start message if applicable */
print "NOTE: Updating csrf_secret file with new information" . PHP_EOL;

$legacy_path = $config['base_path'] . '/include/vendor/csrf/csrf-secret.php';
$new_secret = bin2hex(random_bytes(32));

// Web requests read the secret from $path_csrf_secret when it is set, and
// otherwise from the database; they no longer read the file under include/.
if (empty($config['path_csrf_secret'])) {
    set_config_option('csrf_secret', $new_secret);

    if (read_config_option('csrf_secret', true) !== $new_secret) {
        print "FATAL: Unable to store the new CSRF secret in the database." . PHP_EOL;
        exit(1);
    }

    if (file_exists($legacy_path)) {
        print "NOTE: Removing old csrf_secret.php file." . PHP_EOL;
        @unlink($legacy_path);
    }

    print "NOTE: New CSRF secret stored in the database." . PHP_EOL;
    exit(0);
}

$path_csrf_secret = cacti_csrf_external_secret_path($config['path_csrf_secret']);

if (!cacti_csrf_external_path_is_safe($path_csrf_secret)) {
    print "FATAL: The configured CSRF secret must be outside the Kadupul document root." . PHP_EOL;
    exit(1);
}

if (!file_exists($path_csrf_secret)) {
    print "WARNING: csrf_secret.php file does not exist!" . PHP_EOL;
} elseif (!is_writable($path_csrf_secret)) {
    print "FATAL: unable to unlink csrf_secret.php!" . PHP_EOL;
    exit(1);
} else {
    print "NOTE: Removing old csrf_secret.php file." . PHP_EOL;
    unlink($path_csrf_secret);
}

if (csrf_writable($path_csrf_secret)) {
    umask(0027);
    $fh = fopen($path_csrf_secret, 'w');
    fwrite($fh, '<?php $secret = "' . $new_secret . '";' . PHP_EOL);
    fclose($fh);
    print "NOTE: New csrf_secret.php file written." . PHP_EOL;
    exit(0);
} else {
    print "FATAL: Unable to write new csrf_secret.php file." . PHP_EOL;
    exit(1);
}

/*  display_version - displays version information */
function display_version()
{
    $version = get_cacti_cli_version();
    print "Kadupul Rebuild Poller Cache Utility, Version $version, " . COPYRIGHT_YEARS . PHP_EOL;
}

/*	display_help - displays the usage of the function */
function display_help()
{
    display_version();

    print PHP_EOL . "usage: refresh_csrf.php" . PHP_EOL . PHP_EOL;
    print "A utility to update the csrf_secret() key on a the Kadupul system.  Updating" . PHP_EOL;
    print "this key should happen periodically during non-production hours as it can" . PHP_EOL;
    print "impact the user experience." . PHP_EOL . PHP_EOL;
}
