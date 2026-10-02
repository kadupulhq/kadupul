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
// Generate before touching the working key; entropy failure preserves it.
try {
    $new_secret = csrf_generate_secret();
} catch (Throwable $error) {
    print "FATAL: Unable to generate a new CSRF secret." . PHP_EOL;
    exit(1);
}

// Web requests read the secret from $path_csrf_secret when it is set, and
// otherwise from the database; they no longer read the file under include/.
if (empty($config['path_csrf_secret'])) {
    if (set_config_option('csrf_secret', $new_secret, true) === false) {
        print "FATAL: CSRF secret rotation could not be stored or propagated to every active collector." . PHP_EOL;
        exit(1);
    }

    if (read_config_option('csrf_secret', true) !== $new_secret) {
        print "FATAL: Unable to store the new CSRF secret in the database." . PHP_EOL;
        exit(1);
    }

    if (file_exists($legacy_path)) {
        print "NOTE: Removing old csrf_secret.php file." . PHP_EOL;
        if (!@unlink($legacy_path)) {
            print "FATAL: Unable to remove the old csrf_secret.php file." . PHP_EOL;
            exit(1);
        }
    }

    print "NOTE: New CSRF secret stored in the database." . PHP_EOL;
    exit(0);
}

$path_csrf_secret = cacti_csrf_external_secret_path($config['path_csrf_secret']);

if (!cacti_csrf_external_path_is_safe($path_csrf_secret)) {
    print "FATAL: The configured CSRF secret must be outside the Kadupul document root." . PHP_EOL;
    exit(1);
}

// Keep the working key until its complete replacement is ready.
$previous = is_file($path_csrf_secret) ? stat($path_csrf_secret) : false;
$temporary = tempnam(dirname($path_csrf_secret), '.csrf-');
$contents = '<?php $secret = ' . var_export($new_secret, true) . ';' . PHP_EOL;
$written = false;
if ($temporary !== false) {
    try {
        $preserved_ownership = !$previous || ($config['cacti_server_os'] ?? '') === 'win32' || PHP_OS_FAMILY === 'Windows';
        if (!$preserved_ownership) {
            $temporary_stat = stat($temporary);
            $preserved_ownership = $temporary_stat !== false
                && ($temporary_stat['uid'] === $previous['uid'] || chown($temporary, $previous['uid']))
                && ($temporary_stat['gid'] === $previous['gid'] || chgrp($temporary, $previous['gid']));
        }
        $written = $preserved_ownership
            && file_put_contents($temporary, $contents, LOCK_EX) === strlen($contents)
            && file_get_contents($temporary) === $contents
            && chmod($temporary, $previous ? ($previous['mode'] & 0660) : 0640)
            && rename($temporary, $path_csrf_secret);
    } finally {
        if (file_exists($temporary)) {
            @unlink($temporary);
        }
    }
}

if (!$written) {
    print "FATAL: Unable to atomically replace the configured csrf_secret.php file." . PHP_EOL;
    exit(1);
}

print "NOTE: New csrf_secret.php file written." . PHP_EOL;
exit(0);

/*  display_version - displays version information */
function display_version()
{
    $version = get_cacti_cli_version();
    print "Kadupul CSRF Refresh Utility, Version $version, " . COPYRIGHT_YEARS . PHP_EOL;
}

/*	display_help - displays the usage of the function */
function display_help()
{
    display_version();

    print PHP_EOL . "usage: refresh_csrf.php" . PHP_EOL . PHP_EOL;
    print "A utility to update the csrf_secret() key on the Kadupul system.  Updating" . PHP_EOL;
    print "this key should happen periodically during non-production hours as it can" . PHP_EOL;
    print "impact the user experience." . PHP_EOL . PHP_EOL;
}
