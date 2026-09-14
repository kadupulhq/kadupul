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

/*
 * Any user with the Devices realm can save a location, and the Devices list
 * prints every distinct location into the filter for all users. A single quote
 * in the stored value closed the option's value attribute.
 *
 * The filter also lost the value on the way to the query. applyFilter() sent it
 * unencoded, so an ampersand split it, and sanitize_search_string() dropped the
 * quote, so O'Hare matched the OHare devices. The filter now takes a location
 * only when a device stores exactly that value.
 */

namespace HostLocationFilterEscapeTest;

$root = dirname(__DIR__, 4);
$host = file_get_contents($root . '/host.php');
$html = file_get_contents($root . '/lib/html.php');

if (preg_match('/^function html_escape\(.*?^}\R/ms', $html, $matches) !== 1) {
	throw new \RuntimeException('Unable to extract html_escape().');
}

eval('namespace HostLocationFilterEscapeTest;' . $matches[0]);

if (preg_match('/^function host_filter_location\(.*?^}\R/ms', $host, $matches) === 1) {
	eval('namespace HostLocationFilterEscapeTest;' . $matches[0]);
}

$GLOBALS['location_request'] = array();
$GLOBALS['location_queries'] = array();
$GLOBALS['stored_locations'] = array('O', 'OHare', "O'Hare", 'Tom & Jerry', 'Tom', 'Rack 1', 'Tom &amp; Jerry', '&copy; 2026', '&lt;b&gt;', 'Say "hi"', 'Zürich', 'Lab 2');

/* every stored location is on site 1 unless listed here */
$GLOBALS['stored_sites'] = array('Lab 2' => 2);

function __($text) {
	return $text;
}

function get_request_var($name) {
	return $GLOBALS['location_request'][$name] ?? '-1';
}

/* the escaping lib/database.php applies without a connection */
function db_qstr($s) {
	return "'" . str_replace(array('\\', "\0", "\n", "\r", "'", '"', "\x1a"), array('\\\\', '\\0', '\\n', '\\r', "\\'", '\\"', '\\Z'), $s) . "'";
}

/**
 * Whether a utf8mb4_unicode_ci equality holds: trailing spaces are padded and
 * case is ignored.
 *
 * @param string $a One side.
 * @param string $b The other side.
 *
 * @return bool
 */
function collation_equal($a, $b) {
	return mb_strtolower(rtrim($a, ' ')) === mb_strtolower(rtrim($b, ' '));
}

/**
 * The host rows a prepared equality on host.location returns.
 *
 * @param string            $sql    The query.
 * @param array<int, mixed> $params The bound values.
 *
 * @return array<int, array{location: string}>
 */
function db_fetch_assoc_prepared($sql, $params = array()) {
	$GLOBALS['location_queries'][] = $sql;

	$rows = array();

	foreach ($GLOBALS['stored_locations'] as $location) {
		$site = isset($GLOBALS['stored_sites'][$location]) ? $GLOBALS['stored_sites'][$location] : 1;

		if (collation_equal($location, $params[0]) && (strpos($sql, 'site_id = ?') === false || $site == $params[1])) {
			$rows[] = array('location' => $location);
		}
	}

	return $rows;
}

/**
 * The request value a browser sends when a stored location is picked in the
 * Devices filter.
 *
 * @param string $host     The host.php source.
 * @param string $location The stored host.location value.
 *
 * @return string
 */
function dropdown_request($host, $location) {
	preg_match("/print \"<option value='\" \. .{0,40}?\\\$l\['location'\].*?<\/option>';/", $host, $line);

	$GLOBALS['location_request'] = array();
	$l = array('location' => $location);

	ob_start();
	eval('namespace HostLocationFilterEscapeTest;' . $line[0]);
	preg_match("/value='([^']*)'/", ob_get_clean(), $value);

	$submitted = html_entity_decode($value[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');

	/* encodeURIComponent() leaves these unescaped, rawurlencode() does not */
	$encoded = strtr(rawurlencode($submitted), array('%21' => '!', '%2A' => '*', '%27' => "'", '%28' => '(', '%29' => ')'));

	parse_str('location=' . $encoded . '&rows=-1', $parsed);

	return $parsed['location'];
}

/**
 * Runs a requested location through the Devices filter validation to the WHERE
 * clause get_device_records() builds.
 *
 * @param string $host      The host.php source.
 * @param string $requested The location as PHP received it.
 * @param string $site_id   The selected site, or -1 for all sites.
 *
 * @return array{location: string, where: string}
 */
function filter_where($host, $requested, $site_id = '-1') {
	preg_match("/'location' => (array\(.*?\n\t\t\t\)),/s", $host, $spec);
	preg_match("/\tif \(get_request_var\('location'\) == __\('Undefined'\).*?\n\t}\n/s", $host, $block);

	$options = eval('namespace HostLocationFilterEscapeTest; return ' . $spec[1] . ';');

	/* validate_store_request_vars() keeps an empty value and filters the rest */
	if ($requested === '') {
		$value = '';
	} elseif (isset($options['options'])) {
		$value = filter_var($requested, $options['filter'], $options['options']);
	} else {
		$value = filter_var($requested, $options['filter']);
	}

	$GLOBALS['location_request'] = array('location' => host_filter_location($value, $site_id));

	$sql_where = '';

	eval('namespace HostLocationFilterEscapeTest;' . $block[0]);

	return array('location' => $GLOBALS['location_request']['location'], 'where' => $sql_where);
}

/**
 * Returns the stored locations that a utf8mb4_unicode_ci equality on host.location matches.
 *
 * @param string             $where  The WHERE clause.
 * @param array<int, string> $stored The stored locations.
 *
 * @return array<int, string>
 */
function matched_rows($where, array $stored) {
	if ($where === '') {
		return $stored;
	}

	if (strpos($where, 'IFNULL(host.location,"") = ""') !== false) {
		return array_values(array_filter($stored, function ($row) {
			return $row === '';
		}));
	}

	preg_match("/host\.location = '((?:[^'\\\\]|\\\\.)*)'/", $where, $compared);

	$value = preg_replace('/\\\\(.)/', '$1', $compared[1]);

	return array_values(array_filter($stored, function ($row) use ($value) {
		return collation_equal($row, $value);
	}));
}

test('the Devices location filter encodes the whole option value', function () use ($host) {
	/* The filter matches the stored value exactly, so the browser has to submit
	   it unchanged. html_escape() leaves an existing &amp; for the browser to
	   decode, so every ampersand is encoded here. */
	expect($host)->not->toBeFalse()
		->and($host)->toContain("print \"<option value='\" . htmlspecialchars(\$l['location'], ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8') . \"'\";")
		->and($host)->not->toContain("print \"<option value='\" . \$l['location'] . \"'\";");
});

test('the location reaches validation unencoded by the page and unfiltered by the sanitizer', function () use ($host) {
	expect(function_exists(__NAMESPACE__ . '\host_filter_location'))->toBeTrue();

	expect($host)->toContain("strURL += '&location=' + encodeURIComponent(\$('#location').val());")
		->and($host)->toMatch("/'location' => array\(\s*'filter' => FILTER_DEFAULT,\s*'pageset' => true,\s*'default' => '-1'\s*\),/")
		->and($host)->toMatch("/validate_store_request_vars\(\\\$filters, 'sess_host'\);\s*(?:\/\*.*?\*\/\s*)*set_request_var\('location', host_filter_location\(get_request_var\('location'\), get_request_var\('site_id'\)\)\);/s");
});

test('picking a stored location returns exactly the devices at that location', function () use ($host) {
	expect(function_exists(__NAMESPACE__ . '\host_filter_location'))->toBeTrue();

	$GLOBALS['location_queries'] = array();

	foreach ($GLOBALS['stored_locations'] as $location) {
		$result = filter_where($host, dropdown_request($host, $location));

		expect($result['location'])->toBe($location)
			->and(matched_rows($result['where'], $GLOBALS['stored_locations']))->toBe(array($location));
	}

	expect($GLOBALS['location_queries'][0])->toMatch('/WHERE location = \?/');
});

test('locations the old sanitizer left unchanged return the rows 1.2.31 returned', function () use ($host) {
	expect(function_exists(__NAMESPACE__ . '\host_filter_location'))->toBeTrue();

	/* recorded from release/1.2.31, where these values reach the query as stored */
	$release = array(
		'O'      => " WHERE host.location = 'O'",
		'OHare'  => " WHERE host.location = 'OHare'",
		'Tom'    => " WHERE host.location = 'Tom'",
		'Rack 1' => " WHERE host.location = 'Rack 1'",
	);

	foreach ($release as $location => $where) {
		$result = filter_where($host, dropdown_request($host, $location));

		expect($result['where'])->toBe($where)
			->and(matched_rows($result['where'], $GLOBALS['stored_locations']))->toBe(matched_rows($where, $GLOBALS['stored_locations']));
	}
});

test('an unknown or crafted location selects no location and falls back to All', function () use ($host) {
	expect(function_exists(__NAMESPACE__ . '\host_filter_location'))->toBeTrue();

	/* 'Tom ' is what 1.2.31 received for Tom & Jerry; o'hare and a trailing space
	   match O'Hare under the collation but are not the stored value */
	foreach (array('Unknown', "x' OR '1'='1", "o'hare", "O'Hare ", 'Tom ', "OHare\\") as $requested) {
		expect(filter_where($host, $requested))->toBe(array('location' => '-1', 'where' => ''));
	}

	expect(filter_where($host, '-1'))->toBe(array('location' => '-1', 'where' => ''))
		->and(filter_where($host, ''))->toBe(array('location' => '', 'where' => ' WHERE IFNULL(host.location,"") = ""'))
		->and(filter_where($host, 'Undefined'))->toBe(array('location' => 'Undefined', 'where' => ' WHERE IFNULL(host.location,"") = ""'));
});

test('a location stored only on another site falls back to All once a site is selected', function () use ($host) {
	expect(function_exists(__NAMESPACE__ . '\host_filter_location'))->toBeTrue();

	$GLOBALS['location_queries'] = array();

	/* Lab 2 is only on site 2, Rack 1 only on site 1 */
	expect(filter_where($host, 'Lab 2', '1'))->toBe(array('location' => '-1', 'where' => ''))
		->and(filter_where($host, 'Rack 1', '2'))->toBe(array('location' => '-1', 'where' => ''))
		->and(filter_where($host, 'Lab 2', '2'))->toBe(array('location' => 'Lab 2', 'where' => " WHERE host.location = 'Lab 2'"))
		->and(filter_where($host, 'Rack 1', '1'))->toBe(array('location' => 'Rack 1', 'where' => " WHERE host.location = 'Rack 1'"))
		->and(filter_where($host, 'Lab 2', '-1'))->toBe(array('location' => 'Lab 2', 'where' => " WHERE host.location = 'Lab 2'"));

	expect(implode("\n", $GLOBALS['location_queries']))->toMatch('/WHERE location = \?\s+AND site_id = \?/');

	/* the Location options use the same scope */
	expect($host)->toContain("if (get_request_var('site_id') >= '0') {\n\t\t\t\t\t\t\t\t\$sql_where = 'WHERE site_id = ' . db_qstr(get_request_var('site_id'));");
});
