<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 +-------------------------------------------------------------------------+
*/

require_once dirname(__DIR__, 2) . '/lib/type_secure.php';

/**
 * Mirror of logic in api_automation.php
 */
function simulate_automation_sql_handoff($sort_direction, $page, $rows = 30) {
	$direction = ($sort_direction == 'ASC' ? 'ASC' : 'DESC');
	// Clamped, as the production path in lib/api_automation.php now is: a page
	// that validates to 0 would otherwise produce a negative LIMIT offset, which
	// MySQL rejects.
	$offset = max(0, $rows * (CactiSecureType::toInt($page) - 1));
	
	return " ORDER BY hostname $direction LIMIT $offset,$rows";
}

test('Data Handoff: automation sort direction is allow-listed', function () {
	$malicious_dir = "ASC; DROP TABLE users;";
	$sql = simulate_automation_sql_handoff($malicious_dir, 1);
	
	expect($sql)->toContain('DESC'); // If not 'ASC', defaults to 'DESC'
	expect($sql)->not->toContain('DROP TABLE');
});

test('Data Handoff: automation page is cast to integer', function () {
	$malicious_page = "2' OR 1=1 --";
	$sql = simulate_automation_sql_handoff('ASC', $malicious_page);
	
	expect($sql)->toContain('LIMIT 0,30');
	expect($sql)->not->toContain('OR 1=1');
});
