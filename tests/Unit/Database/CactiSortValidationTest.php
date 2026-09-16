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
