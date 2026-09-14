<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 | Copyright (C) 2026 The Kadupul project and contributors                 |
 +-------------------------------------------------------------------------+
*/

$repoRoot   = dirname(__DIR__, 4);
$ldapSource = file_get_contents($repoRoot . '/lib/ldap.php');
$authSource = file_get_contents($repoRoot . '/lib/auth.php');
$settings   = file_get_contents($repoRoot . '/include/global_settings.php');

test('domains_login_process rejects a realm that is not on the login dropdown', function () use ($authSource) {
	$start = strpos($authSource, 'function domains_login_process(');
	expect($start)->not->toBeFalse();
	$body = substr($authSource, $start, 8000);

	expect($body)->toContain('get_auth_realms(true)');
	expect($body)->toContain('Unknown Login Realm');
});

test('domains_login_process fails closed when the posted realm is not an LDAP domain', function () use ($authSource) {
	$start = strpos($authSource, 'function domains_login_process(');
	$body  = substr($authSource, $start, 8000);

	expect($body)->toContain("Login Realm '%s' is not an LDAP domain");
	expect($body)->not->toContain('$realm >= 3 && $password != \'\'');
});

test('domains_login_process does not interpolate LDAP error_text into the login page', function () use ($authSource) {
	$start = strpos($authSource, 'function domains_login_process(');
	$body  = substr($authSource, $start, 8000);

	expect($body)->not->toContain("__('LDAP Search Error: %s'");
	expect($body)->not->toContain("__('Access Denied!  LDAP Error: %s'");
});

test('domains_login_process leaves a bound user with no domain template to the global template and guest checks', function () use ($authSource) {
	$start = strpos($authSource, 'function domains_login_process(');
	$body  = substr($authSource, $start, 8000);

	expect($body)->not->toContain('Domain template is not configured');
});

test('domains_login_process does not log a missing cn search index', function () use ($authSource) {
	$start = strpos($authSource, 'function domains_login_process(');
	$body  = substr($authSource, $start, 8000);

	expect($body)->not->toContain('$ldap_cn_search_response[0]');
});

test('Authenticate restores the Cacti handler on an empty password', function () use ($ldapSource) {
	$start = strpos($ldapSource, 'function Authenticate()');
	expect($start)->not->toBeFalse();
	$body  = substr($ldapSource, $start, 8000);
	$empty = strpos($body, "password == ''");
	expect($empty)->not->toBeFalse();

	expect(substr($body, $empty, 400))->toContain('RestoreCactiHandler');
});

test('Getcn restores the Cacti handler on bind-only mode', function () use ($ldapSource) {
	$start = strpos($ldapSource, 'function Getcn()');
	expect($start)->not->toBeFalse();
	$body = substr($ldapSource, $start, 4000);
	$mode = strpos($body, "mode == '0'");
	expect($mode)->not->toBeFalse();

	expect(substr($body, $mode, 350))->toContain('RestoreCactiHandler');
});

test('Getcn distinguishes no user from many users', function () use ($ldapSource) {
	$start = strpos($ldapSource, 'function Getcn()');
	$body  = substr($ldapSource, $start, 4000);

	expect($body)->toContain('LdapError::SearchFoundNoUser)');
	expect($body)->toContain('LdapError::SearchFoundMultiUser)');
});

test('username group membership looks up the true DN with the escaped bind DN', function () use ($ldapSource) {
	$start = strpos($ldapSource, 'function Authenticate()');
	$body  = substr($ldapSource, $start, 8000);

	expect($body)->toContain("cacti_ldap_filter('(|(uid=<dn>)(cn=<dn>)(userPrincipalName=<dn>))', array('dn' => \$this->dn))");
});

test('bind timeout is gated on LDAP_OPT_TIMEOUT', function () use ($ldapSource) {
	expect($ldapSource)->toContain("defined('LDAP_OPT_TIMEOUT')");
});

test('new LDAP installs keep the 1.2.31 Never default for TLS certificates', function () use ($settings) {
	expect($settings)->toContain("'default' => LDAP_OPT_X_TLS_NEVER");
	expect($settings)->not->toContain("'default' => LDAP_OPT_X_TLS_DEMAND");
});
