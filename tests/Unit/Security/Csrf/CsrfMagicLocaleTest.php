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
 * Cacti sets LC_CTYPE from the user's language. In a Turkish locale "I"
 * does not lower to "i": PHP 8.1's strtolower() turns ACTION into "actIon",
 * and the PCRE /i flag stops matching SCRIPT against "script" on later
 * versions. The output handler must reach the same decisions in any locale.
 */

$basePath = dirname(__DIR__, 4);
$GLOBALS['config'] = array(
	'base_path'    => $basePath,
	'include_path' => $basePath . '/include',
	'is_web'       => false,
);
$config = $GLOBALS['config'];

require_once($basePath . '/include/csrf.php');
require_once($basePath . '/tests/Helpers/CsrfMagicReferenceWalker.php');

function csrf_locale_pages() {
	return array(
		'upper-case action to another origin' => array("<form method=post ACTION=//evil.example/>", ''),
		'upper-case method and local action'  => array('<FORM METHOD=POST ACTION=graphs.php>', '{F}'),
		'upper-case script hides form text'   => array("<form method=post action=//evil.example/><SCRIPT>'</form>'</SCRIPT><form method=post>", ''),
		'upper-case title hides form text'    => array('<TITLE><form method=post></TITLE><form method=post>', '{F}'),
		'upper-case textarea hides form text' => array('<TEXTAREA><form method=post></TEXTAREA><form method=post>', '{F}'),
		'upper-case base to another origin'   => array("<BASE HREF='//evil.example/'><form method=post action=x.php>", ''),
		'upper-case scheme in the action'     => array("<form method=post action='HTTPS://evil.example/'>", ''),
		'upper-case I in a scheme'            => array("<form method=post action='XI:evil'>", ''),
	);
}

function csrf_locale_check() {
	$failures = array();

	foreach (csrf_locale_pages() as $name => $case) {
		list($page, $marker) = $case;
		$output = csrf_rewrite_forms($page, '{F}');
		$expected = $marker === '' ? $page : $page . '{F}';
		if ($output !== $expected || csrf_reference_rewrite_forms($page, '{F}') !== $expected) {
			$failures[] = $name . ': ' . $output;
		}
	}

	return $failures;
}

test('tag names, methods, schemes and base elements are read the same in a Turkish locale', function () {
	$previous = setlocale(LC_CTYPE, '0');
	if (setlocale(LC_CTYPE, 'tr_TR.UTF-8', 'tr_TR.utf8', 'tr_TR') === false) {
		test()->markTestSkipped('The tr_TR locale is not installed; run this in a container with tr_TR.UTF-8 generated');
	}

	try {
		expect(csrf_locale_check())->toBe(array());
	} finally {
		setlocale(LC_CTYPE, $previous);
	}
});

test('the walkers agree on upper-case markup in a Turkish locale', function () {
	$previous = setlocale(LC_CTYPE, '0');
	if (setlocale(LC_CTYPE, 'tr_TR.UTF-8', 'tr_TR.utf8', 'tr_TR') === false) {
		test()->markTestSkipped('The tr_TR locale is not installed; run this in a container with tr_TR.UTF-8 generated');
	}

	$fragments = array('<FORM METHOD=POST>', '<form method=post ACTION=//evil.example/>', '</FORM>', '<SCRIPT>', '</SCRIPT>',
		'<TITLE>', '</TITLE>', '<TEXTAREA>', '</TEXTAREA>', '<IFRAME>', '</IFRAME>', '<BASE HREF=//evil.example/>',
		'<SELECT>', '</SELECT>', '<TEMPLATE>', '</TEMPLATE>', '<NOSCRIPT>', '<DIV CLASS="I">', '<!--', '-->', "'", 'I', '<');
	mt_srand(7);

	try {
		$mismatches = array();
		for ($i = 0; $i < 2000; $i++) {
			$page = '';
			for ($j = mt_rand(1, 25); $j > 0; $j--) {
				$page .= $fragments[mt_rand(0, count($fragments) - 1)];
			}

			if (csrf_rewrite_forms($page, '{F}') !== csrf_reference_rewrite_forms($page, '{F}')) {
				$mismatches[] = $page;
			}
		}

		expect($mismatches)->toBe(array());
	} finally {
		setlocale(LC_CTYPE, $previous);
	}
});

test('upper-case markup is read the same in the current locale', function () {
	expect(csrf_locale_check())->toBe(array());
});

test('the output handler compares names without locale-dependent functions', function () use ($basePath) {
	$source = file_get_contents($basePath . '/include/vendor/csrf/csrf-magic.php');
	$start = strpos($source, 'function csrf_rewrite_forms(');
	$end = strpos($source, "/**\n * Checks if this is a post request");
	expect($start)->toBeInt()->and($end)->toBeGreaterThan($start);

	// Comments may name the functions; only code counts.
	$walker = '';
	foreach (token_get_all('<?php ' . substr($source, $start, $end - $start)) as $token) {
		if (!is_array($token) || !in_array($token[0], array(T_COMMENT, T_DOC_COMMENT), true)) {
			$walker .= is_array($token) ? $token[1] : $token;
		}
	}

	expect(preg_match_all('/\b(?:strtolower|strtoupper|stripos|strripos|str_ireplace|ucfirst|lcfirst|ucwords|strcasecmp|strncasecmp)\s*\(/', $walker))->toBe(0)
		->and(preg_match_all('/\(\?[a-z]*i[a-z]*[:)]|[#\/~][a-zA-Z]*i[a-zA-Z]*\'\s*[,)]/', $walker))->toBe(0);
});
