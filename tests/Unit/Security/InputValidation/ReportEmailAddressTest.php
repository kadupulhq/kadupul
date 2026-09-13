<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2026 The Kadupul project and contributors                 |
 |                                                                         |
 | This program is free software; you can redistribute it and/or           |
 | modify it under the terms of the GNU General Public License             |
 | as published by the Free Software Foundation; either version 2          |
 | of the License, or (at your option) any later version.                  |
 +-------------------------------------------------------------------------+
*/

/*
 * Report save stored the To, BCC and From address fields as posted, including
 * a request array or a line break or NUL inside an address. The check must
 * refuse only those and keep every list release/1.2.31 saved.
 *
 * mailer() splits these fields on commas, trims each entry and passes it to
 * PHPMailer. The parity cases run that same code: split_emaildetail(),
 * parse_email_details() and add_email_details() from lib/functions.php against
 * the bundled PHPMailer, without sending. The extracted functions live in this
 * namespace because other unit tests load lib/functions.php globally.
 */

namespace ReportEmailAddressTest;

use PHPMailer\PHPMailer\PHPMailer;

$root = dirname(__DIR__, 4);

if (!function_exists(__NAMESPACE__ . '\parse_email_details')) {
	$functions = file_get_contents($root . '/lib/functions.php');
	$code      = '';

	foreach (array('split_emaildetail', 'parse_email_details', 'add_email_details', 'create_emailtext') as $fn) {
		preg_match('/^function ' . $fn . '\(.*?^}\n/ms', $functions, $match);
		expect($match)->not->toBeEmpty();

		$code .= $match[0];
	}

	// test-only eval of source read from this repository, not external input
	eval('namespace ' . __NAMESPACE__ . '; ' . $code);
}

require_once $root . '/include/vendor/phpmailer/src/Exception.php';
require_once $root . '/include/vendor/phpmailer/src/PHPMailer.php';

function load_address_check($root) {
	if (!function_exists(__NAMESPACE__ . '\reports_address_malformed')) {
		preg_match('/^function reports_address_malformed\(.*?^}\n/ms', file_get_contents($root . '/lib/html_reports.php'), $match);
		expect($match)->not->toBeEmpty();

		// test-only eval of source read from this repository, not external input
		eval('namespace ' . __NAMESPACE__ . '; ' . $match[0]);
	}
}

/* the recipients mailer() gives PHPMailer for a To or BCC list, or false where
   mailer() abandons the message */
function mailer_recipients($value) {
	$validator = PHPMailer::$validator;

	$mail = new PHPMailer();
	$mail::$validator = 'eai';

	$result = true;

	add_email_details(parse_email_details($value), $result, array($mail, 'addAddress'));

	PHPMailer::$validator = $validator;

	return ($result == false ? false : array_keys($mail->getAllRecipientAddresses()));
}

dataset('lists 1.2.31 delivers', array(
	'single address'          => array('ops@example.com', array('ops@example.com')),
	'display name'            => array('Ops Team <ops@example.com>', array('ops@example.com')),
	'quoted display name'     => array('"Ops Team" <ops@example.com>', array('ops@example.com')),
	'comma list with spaces'  => array('ops@example.com , noc@example.com', array('ops@example.com', 'noc@example.com')),
	'one entry per line'      => array("ops@example.com,\r\nnoc@example.com", array('ops@example.com', 'noc@example.com')),
	'trailing line break'     => array("ops@example.com,\n", array('ops@example.com')),
	'tab padded'              => array("\tops@example.com\t", array('ops@example.com')),
	'upper case'              => array('OPS@Example.com', array('ops@example.com')),
	'non-ascii local part'    => array('jörg@example.com', array('jörg@example.com')),
	'with an unparsed entry'  => array('ops@example.com, a@example.com; b@example.com', array('ops@example.com')),
));

dataset('values 1.2.31 saved without delivering', array(
	'semicolon separated' => array('ops@example.com; noc@example.com'),
	'bare local user'     => array('root'),
	'empty'               => array(''),
));

dataset('malformed or injected values', array(
	'header after a line break'      => array("ops@example.com\r\nBcc: list@example.net"),
	'header after a display name'    => array("Ops <ops@example.com>\r\nBcc: list@example.net"),
	'two addresses on one line feed' => array("ops@example.com\nnoc@example.com"),
	'NUL byte'                       => array("ops@example.com\0"),
	'array request value'            => array(array('ops@example.com')),
));

test('1.2.31 mailer() delivers these lists to these recipients', function ($value, $recipients) {
	expect(mailer_recipients($value))->toBe($recipients);
})->with('lists 1.2.31 delivers');

test('every list 1.2.31 delivers is accepted', function ($value, $recipients) use ($root) {
	load_address_check($root);

	expect(reports_address_malformed($value))->toBeFalse();
})->with('lists 1.2.31 delivers');

test('every value 1.2.31 saved without delivering is still accepted', function ($value) use ($root) {
	load_address_check($root);

	expect(reports_address_malformed($value))->toBeFalse();
})->with('values 1.2.31 saved without delivering');

test('malformed and injected values are refused', function ($value) use ($root) {
	load_address_check($root);

	expect(reports_address_malformed($value))->toBeTrue();
})->with('malformed or injected values');

test('1.2.31 mailer() never delivered the injected header', function () {
	expect(mailer_recipients("ops@example.com\r\nBcc: list@example.net"))->not->toContain('list@example.net');
	expect(mailer_recipients("Ops <ops@example.com>\r\nBcc: list@example.net"))->toBeFalse();
});

test('report save refuses a malformed To, BCC or From before writing the report', function () use ($root) {
	$src   = file_get_contents($root . '/lib/html_reports.php');
	$start = strpos($src, 'function reports_form_save(');
	$body  = substr($src, $start, strpos($src, "isset_request_var('save_component_report_item')", $start) - $start);

	$check = strpos($body, "foreach (array('email', 'bcc', 'from_email') as \$field) {");
	$sink  = strpos($body, "sql_save(\$save, 'reports')");

	expect($check)->not->toBeFalse();
	expect($body)->toContain('if (reports_address_malformed($save[$field])) {');
	expect($check)->toBeLessThan($sink);
});

test('a duplicate copies a stored list that 1.2.31 still mails', function () use ($root) {
	// a legacy list whose second entry holds a line break still reaches ops@
	expect(mailer_recipients("ops@example.com, a@example.com\r\nb@example.com"))->toBe(array('ops@example.com'));

	$src    = file_get_contents($root . '/lib/html_reports.php');
	$start  = strpos($src, 'REPORTS_DUPLICATE) { // duplicate');
	$branch = substr($src, $start, strpos($src, 'REPORTS_ENABLE) { // enable', $start) - $start);

	expect($branch)->toContain('duplicate_reports($selected_items[$i]');
	expect($branch)->not->toContain('reports_address_malformed(');
});
