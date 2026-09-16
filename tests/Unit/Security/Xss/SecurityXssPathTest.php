<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
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
 * Source-level and contract tests for XSS and path-traversal advisories.
 *
 * GHSA-6233: Stored XSS in report tree titles (lib/reports.php)
 * GHSA-fwh3: Reflected XSS via rfilter in aggregate_graphs.php
 * GHSA-vp35: Path traversal in package import file write (lib/import.php)
 * GHSA-pr9x: Path traversal in package_import.php read
 * GHSA-mjvw: Path traversal via format_file in reports
 */

$reportsPath         = __DIR__ . '/../../../../lib/reports.php';
$aggregateGraphsPath = __DIR__ . '/../../../../aggregate_graphs.php';
$importPath          = __DIR__ . '/../../../../lib/import.php';
$packageImportPath   = __DIR__ . '/../../../../package_import.php';
$htmlReportsPath     = __DIR__ . '/../../../../lib/html_reports.php';

// ---------------------------------------------------------------------------
// GHSA-6233: Stored XSS in report tree titles
// ---------------------------------------------------------------------------

require_once dirname(__DIR__, 3) . '/Helpers/PhpSource.php';
eval(test_php_function_source(file_get_contents(dirname(__DIR__, 4) . '/lib/html.php'), 'html_escape'));
function __($message) { return $message; }

test('report heading expressions render hostile components as text', function ($component) use ($reportsPath) {
    $source = file_get_contents($reportsPath);
    $token = $component === 'report' ? "\$report['name']" : '$' . $component;
    $assignments = array();
    foreach (explode("\n", $source) as $line) {
        if (preg_match('/^\s*\$(title|outstr)\s*\.?=/', $line) && strpos($line, $token) !== false) {
            $assignments[] = trim($line);
        }
    }
    expect($assignments)->toHaveCount(1);
    foreach (array("<script>alert(1)</script>\"' &", 'français 日本語') as $payload) {
        $description = $tree_name = $leaf_name = $host_name = $graph_name = $payload;
        $report = array('name' => $payload);
        $title = $outstr = $title_delimiter = '';
        eval($assignments[0]);
        $html = $component === 'report' ? $outstr : $title;
        $document = new DOMDocument();
        $document->loadHTML('<?xml encoding="UTF-8"><h3>' . $html . '</h3>');
        expect($document->getElementsByTagName('script')->length)->toBe(0)
            ->and($document->getElementsByTagName('h3')->item(0)->textContent)->toContain($payload);
    }
})->with(array('report', 'description', 'tree_name', 'leaf_name', 'host_name', 'graph_name'));

// ---------------------------------------------------------------------------
// GHSA-fwh3: Reflected XSS via rfilter in aggregate_graphs.php
// ---------------------------------------------------------------------------

test('GHSA-fwh3: aggregate_graphs.php escapes rfilter with html_escape_request_var in value attribute', function () use ($aggregateGraphsPath) {
	$contents = file_get_contents($aggregateGraphsPath);

	// html_escape_request_var() must be used instead of raw get_request_var() in the value attribute.
	expect($contents)->toContain("html_escape_request_var('rfilter')");
	expect($contents)->not->toContain("value='<?php print get_request_var('rfilter');?>'");
});

test('GHSA-fwh3: contract — rfilter output in HTML attributes must use html_escape_request_var()', function () use ($aggregateGraphsPath) {
	$contents = file_get_contents($aggregateGraphsPath);

	// html_escape_request_var() is the Cacti convention for encoding HTML attribute values
	// retrieved from request variables. The raw get_request_var() call must be replaced.
	$hasRaw    = str_contains($contents, "value='<?php print get_request_var('rfilter');?>'");
	$hasSafe   = str_contains($contents, "value='<?php print html_escape_request_var('rfilter');?>'");

	// Fails until the advisory is remediated.
	expect($hasRaw)->toBeFalse('raw get_request_var() must be replaced with html_escape_request_var()');
	expect($hasSafe)->toBeTrue('html_escape_request_var() must be used for the rfilter value attribute');
});

// ---------------------------------------------------------------------------
// GHSA-vp35: Path traversal in package import file write (lib/import.php)
// ---------------------------------------------------------------------------

test('GHSA-vp35: str_contains scripts/ prefix check is bypassable with directory traversal', function () {
	// The guard in import.php line 655 accepts any $name that contains the
	// substring 'scripts/'. A crafted name satisfies the check while the
	// resolved path escapes the base directory.
	$name = 'scripts/../../../etc/passwd';

	expect(str_contains($name, 'scripts/'))->toBeTrue();
});

test('GHSA-vp35: traversal payload resolves outside CACTI_PATH_BASE', function () {
	$base     = '/var/www/cacti';
	$name     = 'scripts/../../../etc/passwd';
	$filename = $base . '/' . $name;

	// realpath() would expose the escape; the current code skips this check.
	$resolved = realpath($filename);

	// On a real filesystem the path resolves to /etc/passwd (outside $base).
	// In unit context realpath() returns false for non-existent paths, but
	// the arithmetic remains: stripping the traversal segments leaves a path
	// that does not begin with $base.
	$normalized = implode('/', array_reduce(
		explode('/', $filename),
		function (array $parts, string $seg): array {
			if ($seg === '..') {
				array_pop($parts);
			} elseif ($seg !== '.' && $seg !== '') {
				$parts[] = $seg;
			}

			return $parts;
		},
		[]
	));

	expect(str_starts_with('/' . $normalized, $base . '/'))->toBeFalse();
});

test('GHSA-vp35: contract — import file paths are validated with validate_relative_path_within', function () use ($importPath) {
	$contents = file_get_contents($importPath);

	// The fix must validate the path before writing.
	expect($contents)->toContain('validate_relative_path_within');
});

// ---------------------------------------------------------------------------
// GHSA-pr9x: Path traversal in package_import.php read
// ---------------------------------------------------------------------------

test('GHSA-pr9x: package_import.php validates filename with validate_relative_path_within', function () use ($packageImportPath) {
	$contents = file_get_contents($packageImportPath);

	// The fix validates the filename before reading.
	expect($contents)->toContain("validate_relative_path_within(\$filename, \$config['base_path'])");
	// The old unvalidated file_get_contents must use the validated path.
	expect($contents)->toContain('file_get_contents($validated_path)');
});

// ---------------------------------------------------------------------------
// GHSA-mjvw: Path traversal via format_file in reports
// ---------------------------------------------------------------------------

test('GHSA-mjvw: html_reports.php saves format_file with basename() validation', function () use ($htmlReportsPath) {
	$contents = file_get_contents($htmlReportsPath);

	// The fix applies basename() to strip directory traversal.
	expect($contents)->toContain("basename(get_nfilter_request_var('format_file'))");
	// The old unvalidated assignment must not exist.
	expect($contents)->not->toContain("\$save['format_file']   = \$post['format_file']");
});

test('GHSA-mjvw: a traversal format_file resolves outside CACTI_PATH_FORMATS', function () {
	// reports_load_format_file() prepends CACTI_PATH_FORMATS without
	// sanitizing the stored value, so a stored traversal sequence escapes
	// the formats directory at read time.
	$formatsDir  = '/var/www/cacti/formats';
	$formatFile  = '../../include/config.php';
	$resolved    = $formatsDir . '/' . $formatFile;

	$normalized = implode('/', array_reduce(
		explode('/', $resolved),
		function (array $parts, string $seg): array {
			if ($seg === '..') {
				array_pop($parts);
			} elseif ($seg !== '.' && $seg !== '') {
				$parts[] = $seg;
			}

			return $parts;
		},
		[]
	));

	expect(str_starts_with('/' . $normalized, $formatsDir . '/'))->toBeFalse();
});
