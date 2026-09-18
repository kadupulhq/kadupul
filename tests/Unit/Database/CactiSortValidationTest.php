<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 +-------------------------------------------------------------------------+
*/

require_once dirname(__DIR__, 3) . '/lib/functions.php';
require_once dirname(__DIR__, 3) . '/lib/html_utility.php';

beforeEach(function () {
    $GLOBALS['config'] = array('is_web' => false, 'config_options_array' => array('allow_unsafe_metachars' => ''));
    $_SESSION = array();
    $_REQUEST = array();
    $GLOBALS['_CACTI_REQUEST'] = array();
    $_GET = array();
    $_POST = array();
    $_SERVER['SCRIPT_NAME'] = 'sort-contract.php';
});

test('first-request sorting persists after late allowlist registration', function () {
    set_request_var('sort_column', 'description');
    set_request_var('sort_direction', 'DESC');
    $page = get_order_string_page(false);
    update_order_string();
    expect($_SESSION['sort_data'][$page] ?? array())->toBe(array());
    expect(get_order_string(array('description', 'id')))->toBe('ORDER BY `description` DESC');
    expect($_SESSION['sort_data'][$page])->toBe(array('description' => 'DESC'));

    // A later table with no registered columns cannot erase this table's sort.
    expect(get_order_string())->toBe('');
    expect($_SESSION['sort_data'][$page])->toBe(array('description' => 'DESC'));
    expect($_SESSION['sort_string'][$page])->toBe('ORDER BY `description` DESC');

    // Transfer the persisted page state to the next invocation's table key.
    // The helper's static counter normally resets at the next HTTP request.
    $next = get_order_string_page(false);
    $_SESSION['sort_data'][$next] = $_SESSION['sort_data'][$page];
    $_REQUEST = $GLOBALS['_CACTI_REQUEST'] = array();
    expect(get_order_string(array('description', 'id')))->toBe('ORDER BY `description` DESC');
});

test('late registration never persists rejected sort columns', function () {
    set_request_var('sort_column', 'password');
    set_request_var('sort_direction', 'DESC');
    $page = get_order_string_page(false);
    update_order_string();
    expect(get_order_string(array('description', 'id')))->toBe('ORDER BY `description` ASC');
    expect($_SESSION['sort_data'][$page])->toBe(array('description' => 'ASC'));
    expect($_SESSION['sort_string'][$page])->toBe('ORDER BY `description` ASC');
});

test('sanitize_sql_column() allows valid columns', function () {
	expect(sanitize_sql_column('hostname'))->toBe('hostname');
	expect(sanitize_sql_column('host.id'))->toBe('host.id');
	expect(sanitize_sql_column('ua.full_name'))->toBe('ua.full_name');
});

test('sanitize_sql_column() strips malicious characters', function () {
	expect(sanitize_sql_column("hostname; DROP TABLE users"))->toBe('hostnameDROPTABLEusers');
	expect(sanitize_sql_column("id` OR 1=1 --"))->toBe('idOR11');
	expect(sanitize_sql_column("user_auth.locked"))->toBe('user_auth.locked');
});

test('sanitize_sql_column() handles non-string inputs safely', function () {
	expect(sanitize_sql_column(['a', 'b']))->toBe(''); // Non-scalar input is rejected without a conversion warning.
	expect(sanitize_sql_column(null))->toBe('');
	expect(sanitize_sql_column(123))->toBe('123');
});

test('update_order_string() enforces ASC/DESC direction', function () {
	// Initialize session if not set
	if (session_status() === PHP_SESSION_NONE) {
		@session_start();
	}

	$_SESSION['valid_sort_columns'][get_order_string_page(false)] = array('hostname');
	set_request_var('sort_column', 'hostname');
	set_request_var('sort_direction', 'ASC; --');
	update_order_string();

	$order = get_order_string();
	expect($order)->toContain('ASC');
	expect($order)->not->toContain(';');
	expect($order)->not->toContain('--');
});

test('update_order_string() handles multi-column sorting', function () {
	if (session_status() === PHP_SESSION_NONE) {
		@session_start();
	}

	$page = get_order_string_page(false);
	$_SESSION['valid_sort_columns'][$page] = ['col1', 'col2'];

	// Simulate multiple columns in sort_data
	$_SESSION['sort_data'][$page] = [
		'col1' => 'ASC',
		'col2' => 'DESC'
	];
	
	update_order_string(true); // true for inplace update

	$order = get_order_string();
	expect($order)->toContain('`col1` ASC');
	expect($order)->toContain('`col2` DESC');
});

test('update_order_string() uses session allowlist', function () {
	if (session_status() === PHP_SESSION_NONE) {
		@session_start();
	}

	$page = get_order_string_page(false);
	$_SESSION['valid_sort_columns'][$page] = ['hostname', 'description'];

	set_request_var('sort_column', 'secret_column');
	set_request_var('sort_direction', 'ASC');
	update_order_string();

	$order = get_order_string();
	expect($order)->not->toContain('secret_column');
	
	$_SESSION['valid_sort_columns'][get_order_string_page(false)] = ['hostname', 'description'];
	set_request_var('sort_column', 'description');
	update_order_string();
	$order = get_order_string();
	expect($order)->toContain('description');
});

test('get_order_string() refuses a first request without a table allowlist', function () {
	$_SESSION = [];
	set_request_var('sort_column', 'dangerous` column');
	set_request_var('sort_direction', 'ASC');
	
	$order = get_order_string();
	expect($order)->toBe('');
    set_request_var('sort_column', 'secret_column');
    expect(get_order_string())->toBe('');
});

test('array sort inputs fail closed without PHP warnings', function () {
    set_request_var('sort_column', array('id'));
    set_request_var('sort_direction', array('DESC'));
    expect(get_order_string())->toBe('')
        ->and(cacti_normalize_sort_column(array('id')))->toBe('')
        ->and(cacti_normalize_sort_direction(array('DESC')))->toBe('ASC');
});

test('stored sorts are validated again against the current table allowlist', function () {
    $page = get_order_string_page(false);
    $_SESSION['valid_sort_columns'][$page] = array('hostname', 'LENGTH(description)', 'h.id');
    $_SESSION['sort_data'][$page] = array('secret_column' => 'DESC', 'hostname' => 'ASC', 'LENGTH(description)' => 'DESC', 'h.id' => 'ASC; DROP TABLE host');
    expect(get_order_string())->toBe('ORDER BY INET_ATON(hostname) ASC, LENGTH(description) DESC, `h`.`id` ASC');
});

test('malformed stored sorts fall back to a safe request and empty sorts produce no clause', function () {
    $_SESSION['valid_sort_columns'][get_order_string_page(false)] = array('id');
    $_SESSION['sort_data'][get_order_string_page(false)] = 'id DESC';
    set_request_var('sort_column', 'id');
    set_request_var('sort_direction', 'DESC');
    expect(get_order_string())->toBe('ORDER BY `id` DESC');
    set_request_var('sort_column', '');
    expect(get_order_string())->toBe('');
});


test('explicit server columns preserve first request default sorting', function () {
    set_request_var('sort_column', 'name');
    set_request_var('sort_direction', 'ASC');
    expect(get_order_string(array('name', 'id')))->toBe('ORDER BY `name` ASC');
});

test('explicit columns constrain stored multi-column sorting for this query', function () {
    $page = get_order_string_page(false);
    $_SESSION['valid_sort_columns'][$page] = array('secret_column');
    $_SESSION['sort_data'][$page] = array('name' => 'DESC', 'id' => 'ASC', 'secret_column' => 'DESC');
    expect(get_order_string(array('name', 'id')))->toBe('ORDER BY `name` DESC, `id` ASC');
});


test('automation matching graphs retain the default title order on first render', function () {
    $source = file_get_contents(dirname(__DIR__, 3) . '/lib/api_automation.php');
    $start = strpos($source, 'function display_matching_graphs(');
    expect(preg_match('/get_order_string\(array\(([^\n]+)\)\)/', substr($source, $start), $match))->toBe(1);
    $columns = eval('return array(' . $match[1] . ');');
    set_request_var('sort_column', 'title_cache');
    set_request_var('sort_direction', 'ASC');
    expect(get_order_string($columns))->toBe('ORDER BY `title_cache` ASC');
});

test('late allowlist registration preserves shift-click sorting without admitting unknown columns', function ($column, $expected) {
    $page = get_order_string_page(false);
    $_SESSION['sort_data'][$page] = array('description' => 'ASC');
    set_request_var('sort_column', $column);
    set_request_var('sort_direction', 'DESC');
    set_request_var('add', 'true');
    update_order_string();
    expect(get_order_string(array('description', 'id')))->toBe($expected);
})->with(array(
    array('id', 'ORDER BY `description` ASC, `id` DESC'),
    array('description', 'ORDER BY `description` DESC'),
    array('unknown_column', 'ORDER BY `description` ASC'),
));

test('rejected saved columns fall back to the current allowlisted sort request', function ($requested, $expected, $saved) {
    $page = get_order_string_page(false);
    $_SESSION['valid_sort_columns'][$page] = array('removed_column');
    $_SESSION['sort_data'][$page] = array('removed_column' => 'DESC', 'secret_column' => 'ASC');
    set_request_var('sort_column', $requested);
    set_request_var('sort_direction', 'DESC');
    expect(get_order_string(array('description', 'LENGTH(description)')))->toBe($expected);
    expect($_SESSION['sort_data'][$page])->toBe($saved);
    expect($_SESSION['sort_string'][$page])->toBe($expected);
})->with(array(
    array('description', 'ORDER BY `description` DESC', array('description' => 'DESC')),
    array('LENGTH(description)', 'ORDER BY LENGTH(description) DESC', array('LENGTH(description)' => 'DESC')),
    array('secret_column', 'ORDER BY `description` ASC', array('description' => 'ASC')),
    array('description; DROP TABLE host', 'ORDER BY `description` ASC', array('description' => 'ASC')),
    array(array('description'), 'ORDER BY `description` ASC', array('description' => 'ASC')),
));

test('domain listing sorts LDAP attributes while retaining domains without LDAP settings', function ($column, $direction, $expected, $stringify) {
    $pdo = new PDO(getenv('SORT_TEST_DSN') ?: 'sqlite::memory:', getenv('SORT_TEST_USER') ?: null, getenv('SORT_TEST_PASSWORD') ?: null);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_STRINGIFY_FETCHES, $stringify);
    $pdo->exec('CREATE TEMPORARY TABLE user_domains (domain_id INTEGER PRIMARY KEY, domain_name TEXT, type INTEGER, defdomain INTEGER, user_id INTEGER, enabled TEXT)');
    $pdo->exec('CREATE TEMPORARY TABLE user_domains_ldap (domain_id INTEGER PRIMARY KEY, cn_full_name TEXT, cn_email TEXT)');
    $pdo->exec("INSERT INTO user_domains VALUES (1, 'local', 0, 1, 0, 'on'), (2, 'ldap-a', 1, 0, 0, 'on'), (3, 'ldap-z', 1, 0, 0, 'on')");
    $pdo->exec("INSERT INTO user_domains_ldap VALUES (2, 'Alpha', 'z@example.invalid'), (3, 'Zulu', 'a@example.invalid')");
    set_request_var('sort_column', $column);
    set_request_var('sort_direction', $direction);
    set_request_var('page', 1);
    $rows = 30;
    $sql_where = '';
    $source = file_get_contents(dirname(__DIR__, 3) . '/user_domains.php');
    expect(preg_match('/\$domains = db_fetch_assoc_prepared\((.*?), \$params\);/s', $source, $match))->toBe(1);
    // Execute the actual page's SQL expression, including its live allowlist.
    $sql = eval('return ' . $match[1] . ';');
    $domains = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    // PDO 8.0 returns numeric IDs as strings; ordering must be identical on both driver modes.
    expect(array_map('strval', array_column($domains, 'domain_id')))->toBe(array_map('strval', $expected));
    expect(array_column($domains, 'cn_full_name', 'domain_id')[1])->toBeNull();
})->with(array(
    array('cn_full_name', 'ASC', array(1, 2, 3)),
    array('cn_full_name', 'DESC', array(3, 2, 1)),
    array('cn_email', 'ASC', array(1, 3, 2)),
    array('cn_email', 'DESC', array(2, 3, 1)),
))->with(array(false, true));
