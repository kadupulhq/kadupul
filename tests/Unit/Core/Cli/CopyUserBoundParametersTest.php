<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2026 The Kadupul project and contributors                 |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDtool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
*/

/*
 * copy_user.php looks up both user names it is given on the command line.
 * Those lookups must pass the names as bound parameters, so a name carrying
 * a quote reaches the database as data and never as SQL. The lookup
 * statements run in a child interpreter against a recording database stub.
 */

namespace CopyUserBoundParametersTest;

function lookup_statements() : array {
	$source = file_get_contents(dirname(__DIR__, 4) . '/cli/copy_user.php');

	expect($source)->not->toBeFalse();

	preg_match_all('/^\$user_auth = db_fetch_row[^;]*;$/m', $source, $matches);

	return $matches[0];
}

function run_lookup(string $statement, string $template_user, string $new_user) : array {
	$code = 'function db_fetch_row($sql) { $GLOBALS["calls"][] = array("sql" => $sql, "params" => array()); return array(); }'
		. ' function db_fetch_row_prepared($sql, $params = array()) { $GLOBALS["calls"][] = array("sql" => $sql, "params" => $params); return array(); }'
		. ' $GLOBALS["calls"] = array();'
		. ' $template_user = ' . var_export($template_user, true) . ';'
		. ' $new_user = ' . var_export($new_user, true) . ';'
		. ' ' . $statement
		. ' echo json_encode($GLOBALS["calls"]);';

	$out = array();
	$rc  = 0;

	exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($code) . ' 2>&1', $out, $rc);

	expect($rc)->toBe(0);

	return json_decode(implode('', $out), true);
}

it('looks up the template user and the new user', function () {
	expect(lookup_statements())->toHaveCount(2);
});

it('passes each user name as a bound parameter', function () {
	$template = "admin' OR '1'='1";
	$new      = "copy' -- ";

	foreach (lookup_statements() as $statement) {
		$calls = run_lookup($statement, $template, $new);

		expect($calls)->toHaveCount(1);

		$sql    = $calls[0]['sql'];
		$params = $calls[0]['params'];

		expect($sql)->not->toContain($template)
			->and($sql)->not->toContain($new)
			->and($sql)->toContain('username = ?')
			->and(count($params) === 1 && in_array($params[0], array($template, $new), true))->toBeTrue();
	}
});
