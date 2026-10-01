<?php

/*
 * SPDX-FileCopyrightText: 2004-2026 The Cacti Group
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

require_once dirname(__DIR__) . '/Helpers/PhpSource.php';

$ldapSource = file_get_contents(__DIR__ . '/../../lib/ldap.php');

test('GHSA-pmgm-67h9-59hw: isUserInLDAPGroup routes filter through cacti_ldap_filter', function () use ($ldapSource) {
    $body = test_php_function_source($ldapSource, 'isUserInLDAPGroup');

    // The filter must be assembled by the escaping helper, not by raw
    // string interpolation of $ldapUser / $groupDN.
    expect($body)->toContain('cacti_ldap_filter(');
    expect($body)->toContain('(&(distinguishedName=<user>)(memberOf:1.2.840.113556.1.4.1941:=<group>))');
});

test('GHSA-pmgm-67h9-59hw: isUserInLDAPGroup passes user and group placeholders', function () use ($ldapSource) {
    $body = test_php_function_source($ldapSource, 'isUserInLDAPGroup');

    expect($body)->toContain("'user' => \$ldapUser");
    expect($body)->toContain("'group' => \$groupDN");
});

test('GHSA-pmgm-67h9-59hw: isUserInLDAPGroup does not interpolate user or group into filter string', function () use ($ldapSource) {
    $body = test_php_function_source($ldapSource, 'isUserInLDAPGroup');

    // The vulnerable pattern concatenated $ldapUser and $groupDN directly
    // into the filter. The hardened implementation must not do that.
    expect($body)->not->toContain('"(&(distinguishedName=$ldapUser)');
    expect($body)->not->toContain('"(&(distinguishedName=' . '$ldapUser');
});

test('GHSA-pmgm-67h9-59hw: cacti_ldap_filter escapes each variable with ldap_escape', function () use ($ldapSource) {
    $body = test_php_function_source($ldapSource, 'cacti_ldap_filter');
    expect($body)->toContain("ldap_escape((string) \$value, '', LDAP_ESCAPE_FILTER)");
    expect($body)->toContain("str_replace('<' . \$key . '>', \$escaped, \$result)");
});
