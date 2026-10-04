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
 * The csrf-magic tag walker as it was before the regular-expression skip
 * was added: it reads every tag with csrf_parse_tag(). Tests compare the
 * shipped walker with it, so the speed-up cannot change a decision.
 * Copied from include/vendor/csrf/csrf-magic.php at 0ae9abbb5, with tag
 * names compared as ASCII as the shipped walker does.
 */

function csrf_reference_rewrite_forms($buffer, $input) {
	$scan = csrf_reference_scan_tags($buffer);
	$relative_is_local = csrf_base_is_local($buffer, $scan);
	$form_open = false;
	$select_open = false;
	$templates = 0;
	$output = '';
	$copied = 0;

	foreach ($scan['tags'] as $tag) {
		if ($tag['end_tag']) {
			if ($tag['name'] === 'form' && $templates === 0 && !$select_open) {
				$form_open = false;
			} elseif ($tag['name'] === 'select') {
				$select_open = false;
			} elseif ($tag['name'] === 'template' && $templates > 0) {
				$templates--;
			}
		} elseif ($tag['name'] === 'form') {
			if (!$form_open && !$select_open && csrf_form_is_local_post($tag['attributes'], $relative_is_local)) {
				$output .= substr($buffer, $copied, $tag['end'] - $copied) . $input;
				$copied = $tag['end'];
			}

			// A form inside a template does not set the parser's form pointer.
			if ($templates === 0) {
				$form_open = true;
			}
		} elseif ($tag['name'] === 'select') {
			$select_open = true;
		} elseif ($tag['name'] === 'template') {
			$templates++;
		}
	}

	return $output . substr($buffer, $copied);
}

function csrf_reference_scan_tags($buffer) {
	$letters = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';
	// Only these tags are listed; attributes are read for form and base alone.
	$listed = array('form' => true, 'base' => true, 'select' => false, 'template' => false);
	$raw = array('textarea' => true, 'title' => true, 'script' => true, 'style' => true,
		'xmp' => true, 'iframe' => true, 'noembed' => true, 'noframes' => true);
	// Their content parses differently with scripting off, in SVG or MathML,
	// or in a frameset.
	$unsupported = array('noscript' => true, 'plaintext' => true, 'svg' => true, 'math' => true, 'frameset' => true);
	$tags = array();
	$length = strlen($buffer);
	// Nothing after the last form or base start tag can change a decision.
	$limit = max((int) strripos($buffer, '<form'), (int) strripos($buffer, '<base'));
	$offset = 0;

	while (($start = strpos($buffer, '<', $offset)) !== false && $start <= $limit) {
		$next = $start + 1 < $length ? $buffer[$start + 1] : '';

		if ($next === '!' && substr_compare($buffer, '!--', $start + 1, 3) === 0) {
			$offset = csrf_comment_end($buffer, $start);
			if ($offset === false) {
				return array('tags' => $tags, 'stop' => $start);
			}

			continue;
		}

		$end_tag = $next === '/';
		if ($end_tag) {
			$next = $start + 2 < $length ? $buffer[$start + 2] : '';
			if ($next === '>') {
				$offset = $start + 3;

				continue;
			}
		}

		if ($next === '' || ($next === '!' && substr_compare($buffer, '![CDATA[', $start + 1, 8) === 0)) {
			return array('tags' => $tags, 'stop' => $start);
		}

		if (strspn($next, $letters) === 0) {
			if ($end_tag || $next === '!' || $next === '?') {
				// A bogus comment runs to the next ">".
				$offset = strpos($buffer, '>', $start + 2);
				if ($offset === false) {
					return array('tags' => $tags, 'stop' => $start);
				}

				$offset++;
			} else {
				$offset = $start + 1;
			}

			continue;
		}

		$name_start = $start + ($end_tag ? 2 : 1);
		$name_length = strcspn($buffer, "\t\n\f\r />", $name_start);
		$name = csrf_ascii_lower(substr($buffer, $name_start, $name_length));
		$tag = csrf_parse_tag($buffer, $name_start + $name_length, !$end_tag && !empty($listed[$name]));
		if (!$tag['closed'] || (!$end_tag && isset($unsupported[$name]))) {
			return array('tags' => $tags, 'stop' => $start);
		}

		$offset = $tag['end'];
		if (isset($listed[$name])) {
			$tags[] = array('name' => $name, 'end_tag' => $end_tag, 'attributes' => $tag['attributes'], 'end' => $offset);
		}

		if (!$end_tag && isset($raw[$name])) {
			if (!preg_match('#</' . csrf_ascii_caseless($name) . '(?=[\t\n\f\r />])#', $buffer, $match, PREG_OFFSET_CAPTURE, $offset)) {
				return array('tags' => $tags, 'stop' => $offset);
			}

			// "<!--" inside a script can keep a later "</script>" from ending it.
			$close = $match[0][1];
			if ($name === 'script' && strpos(substr($buffer, $offset, $close - $offset), '<!--') !== false) {
				return array('tags' => $tags, 'stop' => $offset);
			}

			$offset = $close;
		}
	}

	return array('tags' => $tags, 'stop' => false);
}
