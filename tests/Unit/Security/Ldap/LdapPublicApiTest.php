<?php

/* Copyright (C) 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-2.0-or-later */

namespace LdapPublicApiTest;

$source = file_get_contents(dirname(__DIR__, 4) . '/lib/ldap.php');
eval('namespace LdapPublicApiTest; ' . preg_replace('/^<\?php\s*/', '', $source));
$methods = array('__construct', '__destruct', 'ErrorHandler', 'SetLdapHandler', 'RestoreCactiHandler',
	'RecordError', 'Connect', 'Authenticate', 'GetMask', 'Search', 'Getcn', 'isUserInLDAPGroup');

test('LDAP API remains explicitly public with existing callable names', function ($method) use ($source) {
	$reflection = new \ReflectionMethod(Ldap::class, $method);
	expect($reflection->isPublic())->toBeTrue();
	expect($reflection->isStatic())->toBeFalse();
	expect(preg_match('/public function ' . preg_quote($method, '/') . '\\(/', $source))->toBe(1);
})->with($methods);

test('LDAP pure helpers remain callable without configuring or connecting to a directory', function () {
	$ldap = (new \ReflectionClass(Ldap::class))->newInstanceWithoutConstructor();
	expect($ldap->GetMask())->toBe(ENT_COMPAT | ENT_HTML401);
	expect($ldap->ErrorHandler(E_WARNING, 'fixture', __FILE__, __LINE__))->toBeTrue();
});
