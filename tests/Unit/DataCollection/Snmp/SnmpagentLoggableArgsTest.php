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
 | Cacti: The Complete RRDtool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
*/

namespace SnmpagentLoggableArgsTest;

require_once dirname(__DIR__, 3) . '/Helpers/PhpSource.php';

// The production helpers run here; the manager row is the only input.
$functions = file_get_contents(dirname(__DIR__, 4) . '/lib/functions.php');
foreach (array('cacti_sizeof', 'cacti_is_sensitive_key', 'cacti_redact_snmp_command') as $name) {
	eval('namespace ' . __NAMESPACE__ . ';' . \test_php_function_source($functions, $name));
}
eval('namespace ' . __NAMESPACE__ . ';' . \test_php_function_source(file_get_contents(dirname(__DIR__, 4) . '/lib/snmpagent.php'), 'snmpagent_loggable_args'));

test('a passphrase with a quote is masked even though the shell escaped it', function () {
	$manager = array('snmp_password' => "it's secret", 'snmp_priv_passphrase' => "pr'iv", 'snmp_community' => '', 'hostname' => 'receiver');
	$args    = "-v 3 -u admin -l authPriv -a 'SHA' -A 'it'\\''s secret' -x 'AES' -X 'pr'\\''iv' 'receiver:162' \"\" '1.3.6.1'";

	$logged = snmpagent_loggable_args($args, $manager);

	expect($logged)->not->toContain('secret')
		->and($logged)->not->toContain("pr'")
		->and($logged)->toContain('-u admin')
		->and($logged)->toContain("'receiver:162'");
});

test('a v2c community is masked and nothing else changes', function () {
	$logged = snmpagent_loggable_args("-v 2c -c 'publ1c' 'receiver:162' \"\" '1.3.6.1'", array('snmp_community' => 'publ1c', 'hostname' => 'receiver'));

	expect($logged)->not->toContain('publ1c')
		->and($logged)->toContain("'receiver:162'")
		->and($logged)->toContain("'1.3.6.1'");
});
