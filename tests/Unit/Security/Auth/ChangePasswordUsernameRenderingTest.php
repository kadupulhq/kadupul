<?php
declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

/*
 * An account with no stored password sees its username, not a current
 * password field, on the change password page. The username is stored data
 * and must render as text. Upstream Cacti #8338.
 */

require_once dirname(__DIR__, 3) . '/Helpers/PhpSource.php';

function change_password_username_render(array $scenario): string
{
	$root = dirname(__DIR__, 4);
	$work = sys_get_temp_dir() . '/kadupul-acpu-' . bin2hex(random_bytes(6));
	$auth = file_get_contents($root . '/lib/auth.php');
	$html = file_get_contents($root . '/lib/html.php');

	mkdir($work . '/include', 0700, true);

	$global = <<<'PHP'
<?php
$GLOBALS['scenario'] = json_decode(stream_get_contents(STDIN), true);
define('OPER_MODE_NATIVE', 0);
define('OPER_MODE_RESKIN', 2);
define('CACTI_LOCALE', 'en-US');
function auth_session_enforce() {}
define('COPYRIGHT_YEARS_SHORT', '2026');
$config = array('url_path' => '/kadupul/');
$_SESSION = array('sess_user_id' => '42', 'sess_user_credential' => hash('sha256', $GLOBALS['scenario']['password']));
register_shutdown_function(function () {
	$error = error_get_last();
	if ($error === null || !in_array($error['type'], array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR), true)) {
		$GLOBALS['nativeChildCoverageMarkers'] = array('password-page-rendered');
	}
});
class CactiSecureHeaders { public static function getNonceAttribute() { return ''; } }
function cacti_require_post_actions($actions) {}
function set_default_action($default = '') {}
function get_request_var($name, $default = '') { return $default; }
function get_nfilter_request_var($name, $default = '') { return $default; }
function isset_request_var($name) { return false; }
function validate_redirect_url($url = '', $default = 'index.php') { return $default; }
function kill_session_var($name) { unset($_SESSION[$name]); }
function cacti_header($location) { print 'REDIRECT:' . $location; }
function raise_message($id, $message = '', $level = 0) {}
function get_cacti_version() { return '1.2.31'; }
function get_guest_account() { return 0; }
function cacti_sizeof($array) { return is_array($array) ? count($array) : 0; }
function __($text, ...$args) { return vsprintf($text, $args); }
function __esc($text, ...$args) { return html_escape(vsprintf($text, $args)); }
function read_config_option($name, $force = false) { return $GLOBALS['scenario']['config'][$name] ?? ''; }
function api_plugin_hook_function($name, $parm = null) { return $parm; }
function get_selected_theme() { return 'modern'; }
function get_current_page($basename = true) { return 'auth_changepassword.php'; }
function html_common_header($title) {}
function display_tooltip($text) { return ''; }
function db_fetch_row_prepared($sql, $params = array()) {
	return array('id' => 42, 'username' => $GLOBALS['scenario']['username'], 'realm' => 0, 'enabled' => 'on', 'locked' => '',
		'password' => $GLOBALS['scenario']['password'], 'password_change' => 'on', 'must_change_password' => 'on');
}
function db_fetch_cell_prepared($sql, $params = array()) { return false; }
PHP;

	foreach (array('html_escape') as $name) {
		$global .= "\n" . test_php_function_source($html, $name) . "\n";
	}

	file_put_contents($work . '/include/global.php', $global);
	file_put_contents($work . '/include/global_session.php', "<?php\n");

	try {
		$pipes = array();
		$process = proc_open(
			array(PHP_BINARY, '-d', 'display_errors=stderr', '-r', 'require ' . var_export($root . '/auth_changepassword.php', true) . ';'),
			array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w')),
			$pipes,
			$work
		);
		fwrite($pipes[0], json_encode($scenario + array('config' => array('secpass_minlen' => 8, 'secpass_history' => '0'))));
		fclose($pipes[0]);
		$stdout = stream_get_contents($pipes[1]);
		$stderr = stream_get_contents($pipes[2]);
		fclose($pipes[1]);
		fclose($pipes[2]);
		$status = proc_close($process);
	} finally {
		unlink($work . '/include/global_session.php');
		unlink($work . '/include/global.php');
		rmdir($work . '/include');
		rmdir($work);
	}

	expect($status)->toBe(0)->and($stderr)->toBe('');

	return $stdout;
}

function change_password_username_cell(string $page): string
{
	$document = new DOMDocument();
	libxml_use_internal_errors(true);
	$document->loadHTML($page);
	libxml_clear_errors();

	$current = $document->getElementById('current');
	expect($current)->not->toBeNull();

	return $document->saveHTML($current->parentNode);
}

test('the username shown in place of the current password renders as text', function () {
	$username = "<b>alice</b> & 'co'";
	$page = change_password_username_render(array('username' => $username, 'password' => ''));

	expect($page)->not->toContain('REDIRECT:')
		->and($page)->not->toContain('<b>alice</b>');

	$cell = change_password_username_cell($page);
	$document = new DOMDocument();
	$document->loadHTML('<?xml encoding="UTF-8">' . $cell);
	$td = $document->getElementsByTagName('td')->item(0);

	expect($td->getElementsByTagName('b')->length)->toBe(0)
		->and(trim($td->textContent))->toBe($username);
});

test('an account with a stored password is asked for it instead of seeing its username', function () {
	$page = change_password_username_render(array('username' => '<b>alice</b>', 'password' => 'hash:Current1pass'));

	$cell = change_password_username_cell($page);

	expect($cell)->toContain("type=\"password\"")
		->and($cell)->not->toContain('alice');
});
