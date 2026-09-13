#!/usr/bin/env php
<?php
/*
 * SPDX-FileCopyrightText: 2004-2026 The Cacti Group
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

require(__DIR__ . '/../include/cli_check.php');

if (empty($_SERVER['argv'][1]) ){
	display_help();
	exit(1);
} else {
	switch($_SERVER['argv'][1]) {
		case '--help':
		case '-H':
		case '-h':
			display_help();
			exit(0);
		case '--version':
		case '-V':
		case '-v':
			display_version();
			exit(0);
	}
}

/* switch to main database for cli's */
if ($config['poller_id'] > 1) {
	db_switch_remote_to_main();
}

$template_user = $_SERVER['argv'][1];
$new_user      = $_SERVER['argv'][2];

print 'Template User: ' . $template_user . PHP_EOL;
print 'New User:      ' . $new_user . PHP_EOL;

/* Check that user exists */
$user_auth = db_fetch_row("SELECT * FROM user_auth WHERE username = '" . $template_user . "' AND realm = 0");
if (! isset($user_auth)) {
	die("Error: Template user does not exist!" . PHP_EOL . PHP_EOL);
}

print PHP_EOL . 'Copying User...' . PHP_EOL;

if (user_copy($template_user, $new_user) === false) {
	die('Error: User not copied!' . PHP_EOL . PHP_EOL);
}

$user_auth = db_fetch_row("SELECT * FROM user_auth WHERE username = '" . $new_user . "' AND realm = 0");
if (! isset($user_auth)) {
	die('Error: User not copied!' . PHP_EOL . PHP_EOL);
}

print "User copied..." . PHP_EOL;

/*  display_version - displays version information */
function display_version() {
	$version = get_cacti_cli_version();
	print "Kadupul Copy User Utility, Version $version, " . COPYRIGHT_YEARS . PHP_EOL;
}

function display_help() {
	display_version();

	print 'usage: copy_user.php <template user> <new user>' . PHP_EOL . PHP_EOL;
	print 'A utility to copy on local Kadupul user and their settings to a new one.' . PHP_EOL . PHP_EOL;
	print 'NOTE: It is highly recommended that you use the web interface to copy users as' . PHP_EOL;
	print 'this script will only copy Local Kadupul users.' . PHP_EOL . PHP_EOL;
}
