<?php
declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-2.0-or-later

$root = $argv[1];
$scenario = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
$config = array('include_path' => $root . '/include');
$cacti_locale = 'en-US';
$mail_methods = array(2 => 'SMTP');
$logs = array();
function __($text, ...$arguments) { return $arguments ? sprintf($text, ...$arguments) : $text; }
function read_config_option($name) { return $GLOBALS['scenario']['settings'][$name] ?? ''; }
function cacti_log($message, ...$arguments) { $GLOBALS['logs'][] = $message; }
require $root . '/tests/Helpers/PhpSource.php';
require $root . '/include/global_constants.php';
$source = file_get_contents($root . '/lib/functions.php');
foreach (array('ping_mail_server', 'email_test', 'send_mail', 'mailer', 'parse_email_details', 'split_emaildetail', 'create_emailtext', 'add_email_details', 'record_mailer_error', 'cacti_sizeof', 'cacti_count') as $function) {
	eval(test_php_function_source($source, $function));
}
ob_start();
if ($scenario['handler']) {
	email_test();
	$result = null;
} else {
	$settings = $scenario['settings'];
	$result = ping_mail_server($settings['settings_smtp_host'], $settings['settings_smtp_port'],
		$settings['settings_smtp_username'], $settings['settings_smtp_password'], 2, $settings['settings_smtp_secure']);
}
$output = ob_get_clean();
echo json_encode(array('result' => $result, 'output' => $output, 'logs' => $logs), JSON_THROW_ON_ERROR);
