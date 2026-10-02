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

if (empty($config['path_csrf_secret']) && ($config['poller_id'] ?? 1) != 1) {
    print "FATAL: Run database CSRF rotation on the primary Data Collector." . PHP_EOL;
    exit(1);
}

if (empty($config['path_csrf_secret']) && empty($GLOBALS['cacti_csrf_rotation_worker'])) {
    require_once(__DIR__ . '/../lib/csrf_rotation.php');
    $worker = null;
    try {
        $worker = new \Symfony\Component\Process\Process(array(
            PHP_BINARY,
            '-r',
            '$GLOBALS["cacti_csrf_rotation_worker"] = true; $_SERVER["argv"] = array($argv[1]); require $argv[1];',
            __FILE__,
        ));
        $worker->setTimeout(30);
        exit($worker->run(static function (string $type, string $output): void {
            fwrite($type === \Symfony\Component\Process\Process::ERR ? STDERR : STDOUT, $output);
        }));
    } catch (\Throwable $error) {
        if ($worker !== null) {
            try {
                $worker->stop(0);
            } catch (\Throwable $stop_error) {
                // Failure to stop must never become a successful CLI outcome.
            }
        }
        print "FATAL: Database CSRF rotation worker failed or exceeded its deadline." . PHP_EOL;
        exit(1);
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
    $rotated = false;
    try {
        require_once(__DIR__ . '/../lib/csrf_rotation.php');
        $primary = $database_sessions["$database_hostname:$database_port:$database_default"] ?? null;
        $rotated = $primary instanceof \PDO && cacti_rotate_database_csrf_secret(
            $primary,
            $new_secret,
            (int) read_config_option('poller_interval') * 2,
            'poller_connect_to_remote',
            static function (int $id, bool $stale): void {
                raise_message('poller_' . $id, $stale
                    ? __('Settings save to Data Collector %d skipped due to heartbeat.', $id)
                    : __('Settings save to Data Collector %d Failed.', $id), $stale ? MESSAGE_LEVEL_WARN : MESSAGE_LEVEL_ERROR);
            }
        );
    } catch (\Throwable $error) {
        // Database diagnostics can contain credentials; keep the CLI generic.
        $rotated = false;
    }
    if (!$rotated) {
        print "FATAL: CSRF secret rotation could not be stored or propagated to every active collector." . PHP_EOL;
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
