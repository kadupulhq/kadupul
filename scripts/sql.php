<?php

error_reporting(0);

include(dirname(__FILE__) . '/../include/cli_check.php');

global $database_hostname, $database_username, $database_password;

// Keep credentials out of process arguments and remove the private file after use.
$option_file = db_client_option_file($database_username, $database_password);

if ($option_file === false) {
	print 'U';

	exit;
}

$args = array(
	'--defaults-extra-file=' . $option_file,
	'--host=' . $database_hostname,
	'status'
);

try {
	$output = cacti_exec_string('mysqladmin', $args);
} finally {
	unlink($option_file);
}

if ($output === null || $output === '') {
	print 'U';
} else {
	// Extract the 6th field (Queries per second avg), matching original awk '{print $6}'
	$parts = preg_split('/\s+/', trim($output));
	print isset($parts[5]) ? $parts[5] : 'U';
}
