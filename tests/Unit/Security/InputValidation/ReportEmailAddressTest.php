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

		// release/1.2.31 and lts/1.2 refuse no address, so the parity cases pass
		// there and the refusal cases fail
		if (empty($match)) {
			$match = array('function reports_address_malformed($value) { return false; }');
		}

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

/*
 * A refused save redirects to the edit form, which redraws each refused field
 * from $_SESSION['sess_field_values']. reports_form_save(), form_input_validate()
 * and is_error_message() from lib/functions.php, form_text_area() from
 * lib/html_form.php and html_escape() from lib/html.php run as they are; only the
 * request, database, redirect and message calls are stubbed.
 */
function load_save_and_render($root) {
	if (function_exists(__NAMESPACE__ . '\reports_form_save')) {
		return;
	}

	load_address_check($root);

	preg_match_all("/^define\('((?:REPORTS|MESSAGE_LEVEL|POLLER_VERBOSITY)_[A-Z0-9_]+)',\s*([0-9]+)\);/m", file_get_contents($root . '/include/global_constants.php'), $constants, PREG_SET_ORDER);

	foreach ($constants as $constant) {
		if (!defined($constant[1])) {
			define($constant[1], (int) $constant[2]);
		}
	}

	$code    = '';
	$sources = array(
		'lib/html_reports.php' => array('reports_form_save', 'reports_form_actions', 'reports_from_allowed'),
		'lib/functions.php'    => array('form_input_validate', 'is_error_message'),
		'lib/html_form.php'    => array('form_text_area'),
		'lib/html.php'         => array('html_escape'),
	);

	foreach ($sources as $file => $fns) {
		$src = file_get_contents($root . '/' . $file);

		foreach ($fns as $fn) {
			preg_match('/^function ' . $fn . '\(.*?^}\n/ms', $src, $match);

			// release/1.2.31 and lts/1.2 have no From check
			if ($fn == 'reports_from_allowed' && empty($match)) {
				continue;
			}

			expect($match)->not->toBeEmpty();

			$code .= $match[0];
		}
	}

	// each exit follows a recorded redirect, and would otherwise end the run
	// test-only eval of source read from this repository, not external input
	eval('namespace ' . __NAMESPACE__ . '; ' . str_replace('exit;', 'return;', $code));
}

function isset_request_var($name) {
	return isset($GLOBALS['ra_request'][$name]);
}

function isempty_request_var($name) {
	return (!isset($GLOBALS['ra_request'][$name]) || $GLOBALS['ra_request'][$name] == '');
}

function get_nfilter_request_var($name, $default = '') {
	return $GLOBALS['ra_request'][$name] ?? $default;
}

function get_filter_request_var($name, $filter = FILTER_VALIDATE_INT, $options = array()) {
	return $GLOBALS['ra_request'][$name] ?? '';
}

function read_config_option($name) {
	$options = array('poller_interval' => 300, 'settings_from_email' => 'Cacti@example.com', 'settings_from_name' => 'Cacti Reports');

	return $options[$name] ?? '';
}

function raise_message($message_id, $message = '', $message_level = 0) {
	$GLOBALS['ra_messages'][] = $message_id;
}

function cacti_sizeof($array) {
	return (is_array($array) ? count($array) : 0);
}

function cacti_authorize_resource($user_id, $resource_id, $resource_type) {
	return true;
}

function db_fetch_cell_prepared($sql, $params = array()) {
	$sql = preg_replace('/\s+/', ' ', $sql);

	if ($sql == 'SELECT email_address FROM user_auth WHERE id = ?') {
		return ($params == array(5) ? 'Me@Example.com' : false);
	} elseif ($sql == 'SELECT from_email FROM reports WHERE id = ?') {
		return $GLOBALS['ra_from_rows'][(int) $params[0]] ?? false;
	}

	return '5';
}

function sql_save($save, $table_name) {
	$GLOBALS['ra_saved'][] = $save;

	return 7;
}

function get_reports_page() {
	return 'reports_user.php';
}

function header($header) {
	$GLOBALS['ra_headers'][] = $header;
}

function report_save_request($field, $value) {
	return array_merge(array(
		'save_component_report' => '1',
		'id'              => '7',
		'name'            => 'Daily',
		'email'           => 'ops@example.com',
		'font_size'       => '10',
		'alignment'       => '1',
		'graph_columns'   => '2',
		'graph_width'     => '300',
		'graph_height'    => '150',
		'intrvl'          => '1',
		'count'           => '1',
		'mailtime'        => '2099-01-01 10:00',
		'from_email'      => 'cacti@example.com',
		'bcc'             => '',
		'attachment_type' => '1',
	), array($field => $value));
}

dataset('address fields', array(
	'To'   => array('email'),
	'BCC'  => array('bcc'),
	'From' => array('from_email'),
));

test('an array address is refused and the edit form redraws the stored value', function ($field) use ($root) {
	load_save_and_render($root);

	date_default_timezone_set('UTC');

	$_SESSION = array('sess_user_id' => 5);
	$GLOBALS['ra_request']  = report_save_request($field, array('ops@example.com'));
	$GLOBALS['ra_messages'] = array();
	$GLOBALS['ra_saved']    = array();
	$GLOBALS['ra_headers']  = array();

	reports_form_save();

	expect($GLOBALS['ra_saved'])->toBe(array());
	expect($GLOBALS['ra_headers'])->toBe(array('Location: reports_user.php?action=edit&header=false&id=7'));
	expect($GLOBALS['ra_messages'])->toContain(3);

	ob_start();

	try {
		form_text_area($field, 'stored@example.com', 5, 50, '');
	} finally {
		$html = ob_get_clean();
	}

	expect($html)->toContain('txtErrorTextBox');
	expect($html)->toContain('>stored@example.com</textarea>');
})->with('address fields');

function is_reports_admin() {
	return $GLOBALS['ra_admin'] ?? false;
}

function __($text, ...$args) {
	return vsprintf($text, $args);
}

function sanitize_unserialize_selected_items($items) {
	return array('7');
}

function cacti_count($array) {
	return count($array);
}

function reports_log($message, $output = false, $environ = 'REPORTS', $level = 0) {
}

function duplicate_reports($id, $title) {
	$GLOBALS['ra_duplicated'][] = (int) $id;
}

function force_session_data() {
}

function save_from_as($admin, $from) {
	date_default_timezone_set('UTC');

	$_SESSION = array('sess_user_id' => 5);
	$GLOBALS['ra_admin']    = $admin;
	$GLOBALS['ra_request']  = report_save_request('from_email', $from);
	$GLOBALS['ra_messages'] = array();
	$GLOBALS['ra_saved']    = array();
	$GLOBALS['ra_headers']  = array();

	reports_form_save();
}

function duplicate_from_as($admin, $from) {
	$_SESSION = array('sess_user_id' => 5);
	$GLOBALS['ra_admin']      = $admin;
	$GLOBALS['ra_from_rows']  = array(7 => $from);
	$GLOBALS['ra_request']    = array('selected_items' => 'a:1:{i:0;s:1:"7";}', 'drp_action' => (string) REPORTS_DUPLICATE, 'name_format' => '<name> (1)');
	$GLOBALS['ra_duplicated'] = array();
	$GLOBALS['ra_messages']   = array();
	$GLOBALS['ra_headers']    = array();

	reports_form_actions();
}

// the user's account address is Me@Example.com and the site From is Cacti@example.com
dataset('From addresses a user without Reports Administration may use', array(
	'blank for the site default' => array(''),
	'their own address'          => array('me@example.com'),
	'their own named address'    => array('Me <ME@example.com>'),
	'the site From address'      => array('cacti@example.com'),
));

dataset('From addresses only Reports Administration may use', array(
	'another address'         => array('ceo@example.com'),
	'own and another address' => array('me@example.com, ceo@example.com'),
	'a name without address'  => array('Cacti'),
));

test('a user without Reports Administration saves and duplicates a From they may use', function ($from) use ($root) {
	load_save_and_render($root);

	save_from_as(false, $from);

	expect($GLOBALS['ra_saved'])->toHaveCount(1);
	expect($GLOBALS['ra_saved'][0]['from_email'])->toBe($from);

	duplicate_from_as(false, $from);

	expect($GLOBALS['ra_duplicated'])->toBe(array(7));
	expect($GLOBALS['ra_headers'])->toBe(array('Location: reports_user.php?header=false'));
})->with('From addresses a user without Reports Administration may use');

test('a Reports Administration user saves and duplicates any From', function ($from) use ($root) {
	load_save_and_render($root);

	save_from_as(true, $from);

	expect($GLOBALS['ra_saved'])->toHaveCount(1);
	expect($GLOBALS['ra_saved'][0]['from_email'])->toBe($from);

	duplicate_from_as(true, $from);

	expect($GLOBALS['ra_duplicated'])->toBe(array(7));
})->with('From addresses only Reports Administration may use');

test('a user without Reports Administration cannot save or duplicate another From', function ($from) use ($root) {
	load_save_and_render($root);

	save_from_as(false, $from);

	expect($GLOBALS['ra_saved'])->toBe(array());
	expect($GLOBALS['ra_messages'])->toContain('report_message');
	expect($_SESSION['sess_error_fields'] ?? array())->toHaveKey('from_email');

	duplicate_from_as(false, $from);

	expect($GLOBALS['ra_duplicated'])->toBe(array());
	expect($GLOBALS['ra_messages'])->toContain('report_message');
})->with('From addresses only Reports Administration may use');

test('an existing report still opens and sends with the From it holds', function () use ($root) {
	// the From check guards the two writes, a save and a duplicate, not the edit page or the mail
	foreach (array('lib/html_reports.php' => 'reports_edit', 'lib/reports.php' => 'generate_report') as $file => $fn) {
		preg_match('/^function ' . $fn . '\(.*?^}\n/ms', file_get_contents($root . '/' . $file), $match);

		expect($match)->not->toBeEmpty();
		expect($match[0])->not->toContain('reports_from_allowed(');
	}
});

function __esc($text, ...$args) {
	return vsprintf($text, $args);
}

function input_validate_input_number($value) {
}

function db_fetch_row_prepared($sql, $params = array()) {
	return $GLOBALS['ra_send_row'];
}

function generate_report($report, $force = false) {
	$GLOBALS['ra_sent'][] = $report;
}

/*
 * generate_report() hands mailer() the report's From. The From block of mailer()
 * in lib/functions.php runs here as written, against the bundled PHPMailer and
 * the site From address and name in read_config_option(), without sending.
 */
function load_send($root) {
	if (function_exists(__NAMESPACE__ . '\reports_send')) {
		return;
	}

	load_save_and_render($root);

	preg_match('/^function reports_send\(.*?^}\n/ms', file_get_contents($root . '/lib/html_reports.php'), $send);
	preg_match('/^function reports_mail_from\(.*?^}\n/ms', file_get_contents($root . '/lib/reports.php'), $from);

	$functions = file_get_contents($root . '/lib/functions.php');
	$open      = "\t\$from = parse_email_details(\$from, 1);";
	$close     = "array(\$mail, 'setFrom'));";
	$start     = strpos($functions, $open);
	$end       = strpos($functions, $close, (int) $start);

	expect($send)->not->toBeEmpty();
	expect($start)->not->toBeFalse();
	expect($end)->not->toBeFalse();

	// release/1.2.31 and lts/1.2 hand mailer() the stored pair as it is
	if (empty($from)) {
		$from = array('function reports_mail_from($report) { return array($report[\'from_email\'], $report[\'from_name\']); }');
	}

	$block = substr($functions, $start, $end + strlen($close) - $start);

	// test-only eval of source read from this repository, not external input
	eval('namespace ' . __NAMESPACE__ . '; ' . $send[0] . $from[0] . '
		function mailer_from($from) {
			$validator = \PHPMailer\PHPMailer\PHPMailer::$validator;
			$mail = new \PHPMailer\PHPMailer\PHPMailer();
			$mail::$validator = \'eai\';
			' . $block . '
			\PHPMailer\PHPMailer\PHPMailer::$validator = $validator;

			return array(\'sent\' => $result != false, \'email\' => $mail->From, \'name\' => $mail->FromName);
		}');
}

function send_now($from_email, $from_name) {
	$_SESSION = array('sess_user_id' => 5);
	$GLOBALS['ra_send_row'] = array('id' => 7, 'user_id' => 5, 'name' => 'Daily', 'subject' => '', 'email' => 'ops@example.com', 'from_email' => $from_email, 'from_name' => $from_name);
	$GLOBALS['ra_sent']     = array();
	$GLOBALS['ra_messages'] = array();

	reports_send(7);
}

$site_from = array('sent' => true, 'email' => 'Cacti@example.com', 'name' => 'Cacti Reports');

test('1.2.31 mailer() gives up on a blank From address with a From Name', function () use ($root) {
	load_send($root);

	expect(mailer_from(array('', 'Ops Team'))['sent'])->toBeFalse();
	expect(mailer_from(array('', '')))->toBe(array('sent' => true, 'email' => 'Cacti@example.com', 'name' => 'Cacti Reports'));
});

test('a scheduled send of a blank From goes out from the site address and name', function () use ($root, $site_from) {
	load_send($root);

	foreach (array('Ops Team', '') as $name) {
		expect(mailer_from(reports_mail_from(array('from_email' => '', 'from_name' => $name))))->toBe($site_from);
	}
});

test('Send Now sends a blank From from the site address and name and keeps the row', function () use ($root, $site_from) {
	load_send($root);

	foreach (array('Ops Team', '') as $name) {
		send_now('', $name);

		expect($GLOBALS['ra_sent'])->toHaveCount(1);
		expect($GLOBALS['ra_sent'][0]['from_email'])->toBe('');
		expect($GLOBALS['ra_sent'][0]['from_name'])->toBe($name);
		expect(mailer_from(reports_mail_from($GLOBALS['ra_sent'][0])))->toBe($site_from);
	}
});

dataset('stored From addresses', array(
	'a user\'s own address'        => array('me@example.com', 'Ops Team', 'me@example.com'),
	'an admin\'s custom address'   => array('ceo@example.com', 'CEO', 'ceo@example.com'),
	'a named address and no name'  => array('Me <me@example.com>', 'Ops Team', 'me@example.com'),
));

test('a stored From address reaches mailer() as the pair 1.2.31 passes', function ($email, $name, $address) use ($root) {
	load_send($root);

	$report = array('from_email' => $email, 'from_name' => $name);

	expect(reports_mail_from($report))->toBe(array($email, $name));

	$sent = mailer_from(reports_mail_from($report));

	expect($sent['sent'])->toBeTrue();
	expect($sent['email'])->toBe($address);
	expect($sent)->toBe(mailer_from(array($email, $name)));
})->with('stored From addresses');

test('Send Now keeps the 1.2.31 checks for a stored From address', function () use ($root) {
	load_send($root);

	send_now('me@example.com', 'Ops Team');
	expect($GLOBALS['ra_sent'])->toHaveCount(1);

	send_now('ceo@example.com', 'CEO');
	expect($GLOBALS['ra_sent'])->toHaveCount(1);

	send_now('me@example.com', '');
	expect($GLOBALS['ra_sent'])->toBe(array());
	expect($GLOBALS['ra_messages'])->toBe(array('report_message'));
});

test('a scheduled send hands mailer() the From that reports_mail_from() gives', function () use ($root) {
	preg_match('/^function generate_report\(.*?^}\n/ms', file_get_contents($root . '/lib/reports.php'), $match);

	expect($match)->not->toBeEmpty();
	expect($match[0])->toContain("mailer(\n\t\treports_mail_from(\$report),");
});
