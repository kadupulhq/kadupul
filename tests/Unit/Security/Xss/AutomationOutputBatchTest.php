<?php

/* Copyright (C) 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-2.0-or-later */

namespace AutomationOutputBatchTest;

function mb_convert_encoding($value, $to, $from) {
	if (($GLOBALS['automation_conversion_failure'] ?? '') === 'false') {
		return false;
	}
	if (($GLOBALS['automation_conversion_failure'] ?? '') === 'exception') {
		throw new \ValueError('Unsupported configured encoding');
	}
	return \mb_convert_encoding($value, $to, $from);
}

$source = file_get_contents(dirname(__DIR__, 4) . '/lib/api_automation.php');
if (!preg_match('/function automation_url_utf8\(.*?^\}/ms', $source, $match)) {
	throw new \RuntimeException('Missing URL charset helper');
}
eval('namespace AutomationOutputBatchTest; ' . $match[0]);

function get_request_var($name) { return $GLOBALS['automation_batch_payload']; }
function isset_request_var($name) { return true; }
function __($value) { return $value; }
function __esc($value) { return html_escape($value); }
function html_escape_request_var($name) { return html_escape(get_request_var($name)); }
function html_escape($value) {
	return htmlspecialchars(str_replace('`', '&#96;', $value), ENT_QUOTES | ENT_HTML5,
		ini_get('default_charset') ?: 'UTF-8', false);
}

$baseline = json_decode(file_get_contents(__DIR__ . '/automation-output-alert-baseline.json'), true, 512, JSON_THROW_ON_ERROR);
$htmlCases = array();
$scriptCases = array();
foreach ($baseline['issues'] as $issue) {
	if ($issue['context'] === 'html') {
		$htmlCases[$issue['key']] = array($issue);
	} else {
		$scriptCases[$issue['key']] = array($issue);
	}
}
dataset('automation HTML sinks', $htmlCases);
dataset('automation script sinks', $scriptCases);
dataset('automation output payloads', array(
	'ordinary' => array('device-12'),
	'unicode' => array('café'),
	'quotes' => array('\'" autofocus onfocus="alert(1)'),
	'element' => array('</script><script>alert(1)</script><img src=x onerror=alert(1)>'),
	'entities' => array('&amp;#39;&#39;&quot;&amp;'),
	'backtick' => array('a`b\\c'),
	'URL' => array('automation.php?action=edit&id=42&show_rule=1'),
	'empty' => array(''),
));

function production_fragment($issue) {
	$source = file_get_contents(dirname(__DIR__, 4) . '/' . $issue['file']);
	$offset = strpos($source, $issue['after']);
	expect($offset)->not->toBeFalse();
	return substr($source, $offset, strlen($issue['after']));
}

function render_fragment($fragment, $payload) {
	$GLOBALS['automation_batch_payload'] = $payload;
	$url = $payload;
	$field = array('id' => $payload, 'data_name' => $payload);
	$item = array('id' => $payload);
	// One form is emitted inside PHP; the remaining sinks are mixed HTML/PHP templates.
	if (strncmp($fragment, 'print ', 6) === 0 || strncmp($fragment, '$automation_output_', 19) === 0) {
		$fragment = '<?php ' . $fragment . ' ?>';
	}
	ob_start();
	try {
		eval('namespace AutomationOutputBatchTest; ?>' . $fragment);
		return ob_get_contents();
	} finally {
		ob_end_clean();
		unset($GLOBALS['automation_batch_payload']);
	}
}

test('batch records 25 distinct scanner findings and every production occurrence', function () use ($baseline) {
	expect(count($baseline['issues']))->toBe(25);
	expect(count(array_unique(array_column($baseline['issues'], 'key'))))->toBe(25);
	$counts = array();
	foreach ($baseline['issues'] as $issue) {
		production_fragment($issue);
		$key = $issue['file'] . "\n" . $issue['after'];
		$counts[$key] = ($counts[$key] ?? 0) + 1;
	}
	foreach ($counts as $key => $count) {
		list($file, $fragment) = explode("\n", $key, 2);
		$source = file_get_contents(dirname(__DIR__, 4) . '/' . $file);
		expect(substr_count($source, $fragment))->toBe($count);
	}
});

test('automation search controls have associated visible labels', function () {
	$source = file_get_contents(dirname(__DIR__, 4) . '/lib/api_automation.php');
	expect(substr_count($source, "<label for='filterd'>"))->toBe(1);
	expect(substr_count($source, "<label for='filter'>"))->toBe(3);
});

test('HTML output preserves legacy values charset and toggle behavior without attribute injection',
	function ($issue, $payload, $charset, $shown) {
		$previousCharset = ini_get('default_charset');
		$hadSession = array_key_exists('_SESSION', $GLOBALS);
		$previousSession = $_SESSION ?? null;
		ini_set('default_charset', $charset);
		$effective = $charset === '' ? 'UTF-8' : $charset;
		if ($effective !== 'UTF-8') {
			$payload = iconv('UTF-8', $effective, $payload);
		}
		$_SESSION = array('automation_graph_rules_show_rule' => $shown);
		if ($shown) {
			$_SESSION['automation_tree_rules_show_trees'] = true;
			$_SESSION['automation_tree_rules_show_objects'] = true;
		}
		try {
			$html = render_fragment(production_fragment($issue), $payload);
			expect($html)->toBe(render_fragment($issue['before'], $payload));
			$previousErrors = libxml_use_internal_errors(true);
			try {
				$doc = new \DOMDocument();
				$doc->loadHTML('<html><head><meta charset="' . $effective . '"></head><body>' . $html . '</body></html>');
			} finally {
				libxml_clear_errors();
				libxml_use_internal_errors($previousErrors);
			}
			expect($doc->getElementsByTagName('script')->length)->toBe(0);
			expect($doc->getElementsByTagName('img')->length)->toBe(0);
			foreach ($doc->getElementsByTagName('*') as $element) {
				foreach ($element->attributes as $attr) {
					expect(strncmp($attr->name, 'on', 2))->not->toBe(0);
					expect($attr->name)->not->toBe('autofocus');
				}
			}
		} finally {
			ini_set('default_charset', $previousCharset);
			if ($hadSession) {
				$_SESSION = $previousSession;
			} else {
				unset($_SESSION);
			}
		}
	})->with('automation HTML sinks')->with('automation output payloads')
	->with(array('UTF-8', 'ISO-8859-1', ''))->with(array(false, true));

test('script URL is a complete JSON string and preserves the filter suffix', function ($issue, $payload, $charset) {
	$previousCharset = ini_get('default_charset');
	ini_set('default_charset', $charset);
	try {
		$bytes = mb_convert_encoding($payload, $charset ?: 'UTF-8', 'UTF-8');
		$script = render_fragment(production_fragment($issue), $bytes);
	} finally {
		ini_set('default_charset', $previousCharset);
	}
	expect(preg_match('/^(strURL\s*=\s*)("(?:\\\\.|[^"\\\\])*")(.*);$/s', $script, $match))->toBe(1);
	expect(json_decode($match[2], true, 512, JSON_THROW_ON_ERROR))->toBe($payload);
	foreach (array('<', '>', '&', "'") as $token) {
		expect($match[2])->not->toContain($token);
	}
	// Compare the untouched JavaScript around the PHP URL expression.
	$before = explode("'<?php print " . '$url' . ";?>'", $issue['before']);
	expect($match[1])->toBe($before[0]);
	expect($match[3] . ';')->toBe($before[1]);
})->with('automation script sinks')->with('automation output payloads')
	->with(array('UTF-8', 'ISO-8859-1', 'Windows-1252', ''));

test('conversion failures still produce safe string URLs', function ($issue, $failure) {
	$payload = 'automation.php?id=</script>\'"&filter=café';
	$GLOBALS['automation_conversion_failure'] = $failure;
	try {
		$script = render_fragment(production_fragment($issue), $payload);
	} finally {
		unset($GLOBALS['automation_conversion_failure']);
	}
	expect(preg_match('/^strURL\s*=\s*("(?:\\\\.|[^"\\\\])*")/s', $script, $match))->toBe(1);
	expect(json_decode($match[1], true, 512, JSON_THROW_ON_ERROR))->toBe($payload);
	foreach (array('<', '>', '&', "'") as $token) {
		expect($match[1])->not->toContain($token);
	}
})->with('automation script sinks')->with(array('false', 'exception'));
