#!/usr/bin/env php
<?php
/**
 * refresh_csrf.php
 *
 * Rotates the Cacti CSRF secret and removes a stale legacy secret file.
 *
 * @package Cacti\CLI
 */
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 |                                                                         |
 | This program is free software; you can redistribute it and/or           |
 | modify it under the terms of the GNU General Public License             |
 | as published by the Free Software Foundation; either version 2          |
 | of the License, or (at your option) any later version.                  |
 |                                                                         |
 | This program is distributed in the hope that it will be useful,         |
 | but WITHOUT ANY WARRANTY; without even the implied warranty of          |
 | MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the           |
 | GNU General Public License for more details.                            |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDtool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
 | This code is designed, written, and maintained by the Cacti Group. See  |
 | about.php and/or the AUTHORS file for specific developer information.   |
 +-------------------------------------------------------------------------+
 | http://www.cacti.net/                                                   |
 +-------------------------------------------------------------------------+
*/

require(__DIR__ . '/../include/cli_check.php');
require_once($config['base_path'] . '/lib/poller.php');
require_once($config['base_path'] . '/lib/utility.php');

/* process calling arguments */
$parms = $_SERVER['argv'];
array_shift($parms);

if (cacti_sizeof($parms)) {
	foreach($parms as $parameter) {
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

$external = !empty($config['path_csrf_secret']);

if ($external) {
	$path_csrf_secret = cacti_csrf_external_secret_path($config['path_csrf_secret']);
	if (!cacti_csrf_external_path_is_safe($path_csrf_secret)) {
		print "FATAL: The configured CSRF secret must be outside the Cacti document root." . PHP_EOL;
		exit(1);
	}
} else {
	/* The default secret lives in settings now; this is the file older
	 * releases kept it in, reported as they reported it. */
	$path_csrf_secret = $config['base_path'] . '/include/vendor/csrf/csrf-secret.php';
}

if (!file_exists($path_csrf_secret)) {
	print "WARNING: csrf_secret.php file does not exist!" . PHP_EOL;
} elseif (!is_writable($path_csrf_secret)) {
	/* A stale vendor file no longer holds the secret, so it can not block the rotation. */
	if ($external) {
		print "FATAL: unable to unlink csrf_secret.php!" . PHP_EOL;
		exit(1);
	}
} else {
	print "NOTE: Removing old csrf_secret.php file." . PHP_EOL;
}

if ($external) {
	$written = csrf_write_secret_atomic($path_csrf_secret, csrf_generate_secret());
} else {
	$new_secret = csrf_generate_secret();
	set_config_option('csrf_secret', $new_secret, true);
	$stored_secret = read_config_option('csrf_secret', true);
	$written = (is_string($stored_secret) && hash_equals($new_secret, $stored_secret));
}

if ($written) {
	$legacy_path = $config['base_path'] . '/include/vendor/csrf/csrf-secret.php';
	if (file_exists($legacy_path) && is_writable($legacy_path)) {
		@unlink($legacy_path);
	}

	print "NOTE: New csrf_secret.php file written." . PHP_EOL;
	exit(0);
}

print "FATAL: Unable to write new csrf_secret.php file." . PHP_EOL;
exit(1);

/*  display_version - displays version information */
function display_version() {
	$version = get_cacti_cli_version();
	print "Cacti Rebuild Poller Cache Utility, Version $version, " . COPYRIGHT_YEARS . PHP_EOL;
}

/*	display_help - displays the usage of the function */
function display_help () {
	display_version();

	print PHP_EOL . "usage: refresh_csrf.php" . PHP_EOL . PHP_EOL;
	print "A utility to update the csrf_secret() key on a the Cacti system.  Updating" . PHP_EOL;
	print "this key should happen periodically during non-production hours as it can" . PHP_EOL;
	print "impact the user experience." . PHP_EOL . PHP_EOL;
}
