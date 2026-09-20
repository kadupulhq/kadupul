<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

if (($argv[1] ?? '') === '--capture') {
    // Inert sendmail replacement: save stdin, never deliver a message.
    exit(file_put_contents($argv[2], stream_get_contents(STDIN)) === false ? 1 : 0);
}

$root = $argv[1];
$config = array(
    'is_web' => false,
    'include_path' => $root . '/include',
    'base_path' => $root,
    'config_options_array' => array(
        'settings_smtp_timeout' => '5',
        'settings_how' => '1',
        'settings_sendmail_path' => $argv[3],
        'settings_wordwrap' => '76',
        'settings_from_name' => '',
        'selective_debug' => '',
        'client_timezone_support' => '',
        'path_cactilog' => $argv[2] . '/unused.log',
        'log_destination' => '0',
    ),
);
$mail_methods = array(1 => 'inert capture');
require $root . '/include/global_constants.php';
require $root . '/lib/functions.php';
$results = array();
foreach (array('de-DE', 'en-US', 'unknown-XX') as $cacti_locale) {
    $error = mailer('sender@example.invalid', 'recipient@example.invalid', '', '', '', 'Compatibility', '<b>HTML body</b>', 'Plain body', array(array('attachment' => 'sample attachment', 'filename' => 'sample.txt')), '', true);
    $mailer = new PHPMailer\PHPMailer\PHPMailer();
    $results[] = array('error' => $error, 'language' => $mailer->getTranslations()['authenticate']);
}
echo json_encode($results, JSON_THROW_ON_ERROR);
