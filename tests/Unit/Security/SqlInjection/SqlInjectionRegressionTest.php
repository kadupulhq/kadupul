<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 | Copyright (C) 2026 The Kadupul project and contributors                 |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDtool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
*/

/*
 * Consolidated SQL injection regression tests.
 *
 * Combines the previously separate per-advisory test files for:
 * GHSA-3p6w-h4wv-6x7g, GHSA-69gg-mjfm-jjpc, GHSA-72vr-jr4v-55vf,
 * GHSA-gp82-qhrg-crv7, GHSA-j9jv-6xjq-9hhj, GHSA-q9xg-p762-9jm3,
 * GHSA-xrh3-6pfg-ff35, and the SQL-injection assertion from
 * GHSA-cfhh-pwvx-gp5g.
 *
 * Each test below keeps its original GHSA identifier in its description
 * so the advisory it guards against remains traceable.
 */

require_once dirname(__DIR__, 3) . '/Helpers/PhpSource.php';

$utilitiesSource      = file_get_contents(__DIR__ . '/../../../../utilities.php');
$databaseSource       = file_get_contents(__DIR__ . '/../../../../lib/database.php');
$graphViewSource      = file_get_contents(__DIR__ . '/../../../../graph_view.php');
$reportsSource        = file_get_contents(__DIR__ . '/../../../../lib/reports.php');
$aggregateGraphsSource = file_get_contents(__DIR__ . '/../../../../aggregate_graphs.php');
$htmlReportsSource    = file_get_contents(__DIR__ . '/../../../../lib/html_reports.php');
$managersSource       = file_get_contents(dirname(__DIR__, 4) . '/managers.php');
$apiAutomationSource  = file_get_contents(__DIR__ . '/../../../../lib/api_automation.php');
$htmlUtilitySource    = file_get_contents(__DIR__ . '/../../../../lib/html_utility.php');

// GHSA-3p6w: utilities.php sort_column sites. The per-call cacti_validate_sort_column()
// allowlists were folded into get_order_string(), which validates the column before
// it reaches ORDER BY.
test('GHSA-3p6w: utilities.php builds ORDER BY through get_order_string', function () use ($utilitiesSource) {
	expect($utilitiesSource)->not->toBeFalse();
	// view_user_log and view_poller_cache
	expect($utilitiesSource)->toContain("\t\t\" . get_order_string(array('username', 'full_name', 'realm', 'time', 'result', 'ip')) . \"\n");
	expect($utilitiesSource)->toContain("\$order_string = get_order_string(array('action', 'dtd.name_cache', 'h.description'));");
	expect($utilitiesSource)->not->toMatch('/ORDER BY[^;]*get_(nfilter_|filter_)?request_var\(\'sort_(column|direction)\'\)/');
});

test('GHSA-3p6w: get_order_string validates the requested sort column and clamps direction', function () use ($htmlUtilitySource) {
	$body = test_php_function_source($htmlUtilitySource, 'get_order_string');

	expect($body)->toContain("cacti_normalize_sort_column(\$column)");
	expect($body)->toContain('$column = validate_sort_column($column, $page);');
	expect($body)->toContain("cacti_normalize_sort_direction(\$direction)");
	expect($htmlUtilitySource)->toContain("preg_match('/^[a-zA-Z][a-zA-Z0-9_]*(?:\\.[a-zA-Z][a-zA-Z0-9_]*)*\$/', \$column)");
});

// GHSA-69gg / GHSA-xrh3 / GHSA-gp82 / GHSA-pf37: db_qstr_rlike() hardening.
test('GHSA-69gg/xrh3/gp82/pf37: db_qstr_rlike caps operand length at 255 bytes', function () use ($databaseSource) {
	$start = strpos($databaseSource, 'function db_qstr_rlike(');
	expect($start)->not->toBeFalse();

	$end  = strpos($databaseSource, "\n}\n", $start);
	$body = substr($databaseSource, $start, $end - $start);

	// Long regex operands caused DoS against MySQL's RE2. The cap keeps
	// the pattern bounded before it reaches the engine.
	expect($body)->toContain('strlen($s) > 255');
	expect($body)->toContain('substr($s, 0, 255)');
});

test('GHSA-69gg/xrh3/gp82/pf37: db_qstr_rlike strips NUL, pipe and brace metacharacters', function () use ($databaseSource) {
	$start = strpos($databaseSource, 'function db_qstr_rlike(');
	$end   = strpos($databaseSource, "\n}\n", $start);
	$body  = substr($databaseSource, $start, $end - $start);

	// Alternation and quantifier-bound constructs were the DoS vector;
	// stripping them here is belt-and-braces on top of the length cap.
	expect($body)->toContain('str_replace(array("\0", \'|\', \'{\', \'}\'), \'\', $s)');
});

test('GHSA-69gg/xrh3/gp82/pf37: db_qstr_rlike returns a quoted RLIKE fragment', function () use ($databaseSource) {
	$start = strpos($databaseSource, 'function db_qstr_rlike(');
	$end   = strpos($databaseSource, "\n}\n", $start);
	$body  = substr($databaseSource, $start, $end - $start);

	// The return shape is what callers rely on when concatenating into
	// WHERE clauses; a drift here would re-open SQL injection.
	expect($body)->toContain("return 'RLIKE ' . db_qstr(");
});

test('GHSA-69gg / GHSA-gp82: graph_view.php routes rfilter through db_qstr_rlike', function () use ($graphViewSource) {
	expect($graphViewSource)->toContain("db_qstr_rlike(get_request_var('rfilter'))");
});

test('GHSA-xrh3 / GHSA-pf37: lib/reports.php wraps graph_name_regexp in db_qstr_rlike', function () use ($reportsSource) {
	expect($reportsSource)->toContain("db_qstr_rlike(\$item['graph_name_regexp'])");
});

test('GHSA-69gg/xrh3/gp82/pf37: aggregate_graphs.php routes rfilter through db_qstr_rlike', function () use ($aggregateGraphsSource) {
	expect($aggregateGraphsSource)->toContain("db_qstr_rlike(get_request_var('rfilter'))");
});

// GHSA-72vr: ORDER BY sort_column and sort_direction hardening in lib/html_reports.php.
test('GHSA-72vr: html_reports ORDER BY uses cacti_validate_sort_column', function () use ($htmlReportsSource) {
	expect($htmlReportsSource)->toContain('cacti_validate_sort_column(get_request_var(\'sort_column\')');
});

test('GHSA-72vr: html_reports sort_column allowlist maps request keys to fixed SQL columns', function () use ($htmlReportsSource) {
	expect($htmlReportsSource)->toContain("cacti_validate_sort_column(get_request_var('sort_column'), array_keys(\$sort_columns), 'name')");
	expect($htmlReportsSource)->toMatch('/\$sortby\s*=\s*\$sort_columns\[\$sort_column\];/');

	$expected = array(
		'name'            => 'reports.name',
		'full_name'       => 'user_auth.full_name',
		'cint'            => 'cint',
		'lastsent'        => 'reports.lastsent',
		'mailtime'        => 'reports.mailtime',
		'from_name'       => 'reports.from_name',
		'attachment_type' => 'reports.attachment_type',
		'enabled'         => 'reports.enabled',
	);

	foreach ($expected as $key => $column) {
		expect($htmlReportsSource)->toMatch('/\'' . preg_quote($key, '/') . '\'\s*=>\s*\'' . preg_quote($column, '/') . '\'/');
	}
});

test('GHSA-72vr: html_reports sort_direction is clamped to ASC or DESC', function () use ($htmlReportsSource) {
	expect($htmlReportsSource)->toContain("strtoupper(get_request_var('sort_direction')) === 'DESC' ? 'DESC' : 'ASC'");
});

test('GHSA-72vr: html_reports does not concatenate raw sort_column into ORDER BY', function () use ($htmlReportsSource) {
	// ORDER BY may only interpolate $sortby, which comes from the fixed map above.
	$orderByPos = strpos($htmlReportsSource, 'ORDER BY $sortby " .');
	expect($orderByPos)->not->toBeFalse();

	$fragment = substr($htmlReportsSource, $orderByPos, 400);
	expect($fragment)->not->toContain("get_request_var('sort_column')");
	expect($htmlReportsSource)->not->toMatch('/ORDER BY[^;]*get_(nfilter_|filter_)?request_var\(\'sort_column\'\)/');
});

test('GHSA-72vr: html_reports does not concatenate raw sort_direction into ORDER BY', function () use ($htmlReportsSource) {
	$orderByPos = strpos($htmlReportsSource, 'ORDER BY $sortby " .');
	expect($orderByPos)->not->toBeFalse();

	$fragment = substr($htmlReportsSource, $orderByPos, 400);
	expect($fragment)->toContain("(strtoupper(get_request_var('sort_direction')) === 'DESC' ? 'DESC' : 'ASC') .");
	expect($fragment)->not->toContain("get_request_var('sort_direction') . ' LIMIT'");
});

// GHSA-gp82: unanchored FILTER_VALIDATE_REGEXP for graph_view.php 'thumbnails'.
test('GHSA-gp82: graph_view.php thumbnails regex is anchored', function () use ($graphViewSource) {
	expect($graphViewSource)->toContain("'regexp' => '^(true|false)\$'");
	expect($graphViewSource)->not->toMatch("/'regexp' => '\\(true\\|false\\)'/");
});

test('GHSA-gp82: anchored regex behavior', function () {
	$pattern = '/^(true|false)$/';
	expect(preg_match($pattern, 'true'))->toBe(1);
	expect(preg_match($pattern, 'false'))->toBe(1);
	expect(preg_match($pattern, "true' OR 1=1--"))->toBe(0);
	expect(preg_match($pattern, "fasetrue"))->toBe(0);
});

// GHSA-xrh3: stored SQL injection via graph_name_regexp in lib/reports.php.
test('GHSA-xrh3: lib/reports.php contains no raw REGEXP concat with graph_name_regexp', function () use ($reportsSource) {
	expect($reportsSource)->not->toContain("REGEXP '\" . \$item['graph_name_regexp'] . \"'");
});

test('GHSA-xrh3: lib/reports.php uses db_qstr_rlike for all graph_name_regexp sinks', function () use ($reportsSource) {
	$matches = substr_count($reportsSource, "db_qstr_rlike(\$item['graph_name_regexp'])");
	expect($matches)->toBeGreaterThanOrEqual(4);
});

// GHSA-j9jv: SQL injection via cacti_unserialize in managers.php. The form_actions
// handler imploded the post-unserialize array straight into IN (...) clauses. Even
// though cacti_unserialize blocks object injection, it still returns string values,
// which then reach the SQL layer verbatim. The fix casts each value via intval()
// before implode().
test('GHSA-j9jv: selected_items passed through intval coercion rejects string payloads', function () {
	$payload = ["1 OR 1=1", "2; DROP TABLE snmpagent_managers"];

	$coerced = array_values(array_filter(array_map('intval', $payload)));

	expect($coerced)->toBe([1, 2]);
});

test('GHSA-j9jv: intval coercion drops non-numeric entries that would leak into IN clause', function () {
	$payload = ['xyz', 'DROP', '42'];

	$coerced = array_values(array_filter(array_map('intval', $payload)));

	expect($coerced)->toBe([42]);
});

test('GHSA-j9jv: intval coercion preserves legitimate integer ids', function () {
	$payload = [1, 2, 3, 10];

	$coerced = array_values(array_filter(array_map('intval', $payload)));

	expect($coerced)->toBe([1, 2, 3, 10]);
});

test('GHSA-j9jv: managers.php form_actions applies array_map intval before implode', function () use ($managersSource) {
	expect($managersSource)->toContain("array_map('intval', \$selected_items)");
});

test('GHSA-j9jv: managers.php form_actions no longer implodes unsanitized selected_items', function () use ($managersSource) {
	$lines = explode("\n", $managersSource);

	$unsafePattern = "/implode\([^)]*,\s*\\\$selected_items\s*\)/";
	$imploded      = [];

	foreach ($lines as $n => $line) {
		if (preg_match($unsafePattern, $line)) {
			$imploded[] = $n + 1;
		}
	}

	foreach ($imploded as $lineNo) {
		$context = implode("\n", array_slice($lines, max(0, $lineNo - 30), 30));
		expect($context)->toContain("array_map('intval', \$selected_items)");
	}
});

// GHSA-q9xg: ORDER BY sort_column and sort_direction hardening in lib/api_automation.php.
test('GHSA-q9xg: api_automation ORDER BY uses cacti_validate_sort_column at both device list call sites', function () use ($apiAutomationSource) {
	// Both device lists validate the request key against a fixed column map.
	expect(substr_count($apiAutomationSource, "cacti_validate_sort_column(get_request_var('sort_column'), array_keys(\$sort_columns), 'description')"))->toBeGreaterThanOrEqual(2);
	expect(preg_match_all('/\$sortby\s*=\s*\$sort_columns\[\$sort_column\];/', $apiAutomationSource))->toBeGreaterThanOrEqual(2);
});

test('GHSA-q9xg: api_automation sort_direction is clamped to ASC or DESC at the fixed call site', function () use ($apiAutomationSource) {
	// Must appear in the block that builds $sql_query after the validate call.
	expect($apiAutomationSource)->toContain("strtoupper(get_request_var('sort_direction')) === 'DESC' ? 'DESC' : 'ASC'");
});

test('GHSA-q9xg: api_automation hostname sort maps to a fixed INET_ATON expression', function () use ($apiAutomationSource) {
	// The former $sortby === 'h.hostname' rewrite became a map entry, so the
	// INET_ATON() wrapper is fixed SQL rather than built around a request value.
	expect(preg_match_all('/\'hostname\'\s*=>\s*\'INET_ATON\(h\.hostname\)\',/', $apiAutomationSource))->toBeGreaterThanOrEqual(2);
	expect($apiAutomationSource)->not->toMatch('/\$sortby\s*==\s*\'h\.hostname\'/');
});

test('GHSA-q9xg: api_automation ORDER BY at fixed site does not build sortby from raw request var', function () use ($apiAutomationSource) {
	// The fixed pattern must NOT assign $sortby directly from get_request_var without the helper.
	// Old: $sortby = get_request_var('sort_column');
	// New: $sortby = cacti_validate_sort_column(get_request_var(...), ...);
	// Verify the bare assignment form is gone.
	expect($apiAutomationSource)->not->toContain('$sortby = get_request_var(\'sort_column\')');
});

// GHSA-cfhh: SQL-injection half of the aggregate rfilter advisory (XSS half lives
// in the Xss regression group).
test('GHSA-cfhh: rfilter SQL side still runs through db_qstr_rlike', function () use ($aggregateGraphsSource) {
	// XSS fix must not regress the parallel SQL injection guard that
	// covers the same request variable.
	expect($aggregateGraphsSource)->toContain("db_qstr_rlike(get_request_var('rfilter'))");
});
