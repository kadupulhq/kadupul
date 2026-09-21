<?php

namespace AdminOutputContextTest;

$root = dirname(__DIR__, 4);

test('admin search fields preserve literal text in a single attribute', function ($file, $expected, $payload) use ($root) {
	$source = file_get_contents($root . '/' . $file);
	$lines = explode("\n", preg_replace('/<\?php\s+print\s+/', '<?php print ', $source));
	$count = 0;
	$ids = array();
	foreach ($lines as $line) {
		if (strpos($line, "get_request_var('filter')") === false || strpos($line, '<input') === false) {
			continue;
		}
		$count++;
		$output = render_output_line($line, $payload);
		$document = new \DOMDocument();
		$document->loadHTML('<!doctype html><html><head><meta charset="UTF-8"></head><body>'
			. $output . '</body></html>');
		$inputs = $document->getElementsByTagName('input');
		expect($inputs->length)->toBe(1);
		expect($inputs->item(0)->getAttribute('value'))->toBe($payload);
		expect($inputs->item(0)->attributes->length)->toBe(5);
		expect($inputs->item(0)->getAttribute('class'))->toContain('adminFilter');
		$id = $inputs->item(0)->getAttribute('id');
		expect($id)->not->toBe('');
		expect($ids)->not->toContain($id);
		$ids[] = $id;
		expect($source)->toContain("<label for='" . $id . "'>");
		expect($source)->toContain("$('#" . $id . "').val()");
		expect($document->getElementsByTagName('script')->length)->toBe(0);
		expect($document->getElementsByTagName('img')->length)->toBe(0);
	}
	expect($count)->toBe($expected);
	$layout = file_get_contents($root . '/include/layout.js');
	expect($layout)->toContain("$('#filter, #rfilter, .adminFilter').focus()");
	expect($layout)->toContain("$('#filter, #rfilter, .adminFilter').prop('size', '15')");
	expect($layout)->toContain("$('#filter, #rfilter, .adminFilter').on('keydown'");
})->with(array(
	'user filters' => array('user_admin.php', 7),
	'group filters' => array('user_group_admin.php', 6),
))->with(array(
	'normal search' => 'router 42',
	'empty search' => '',
	'Unicode search' => 'réseau 日本語',
	'attribute boundary' => '\' autofocus onfocus="alert(1)',
	'element boundary' => '\'><img src=x onerror=alert(1)><script>alert(1)</script>',
	'entity-like text' => '&#39;&quot;&amp;',
	'backtick and delimiters' => '` & # % +',
));

function get_request_var($name) {
	return $GLOBALS['admin_output_payload'];
}

function render_output_line($line, $payload) {
	$tab = $payload;
	$GLOBALS['admin_output_payload'] = $payload;
	ob_start();
	try {
		eval('namespace AdminOutputContextTest; ?>' . $line);
		return ob_get_contents();
	} finally {
		ob_end_clean();
		unset($GLOBALS['admin_output_payload']);
	}
}

test('admin IDs and tabs retain their values without breaking output contexts', function ($file, $expected, $payload) use ($root) {
	$source = file_get_contents($root . '/' . $file);
	/* Whitespace inside PHP tags does not affect the rendered output. */
	$lines = explode("\n", preg_replace('/<\?php\s+print\s+/', '<?php print ', $source));
	$count = 0;
	foreach ($lines as $line) {
		$isId = strpos($line, "get_request_var('id')") !== false;
		$isTab = preg_match('/print (?:html_escape|htmlspecialchars)\(.*\$tab/', $line) === 1
			|| strpos($line, 'print $tab;') !== false;
		$isUrl = strpos($line, 'strURL') !== false;
		$isInput = strpos($line, '<input') !== false;
		if ((!$isId && !$isTab) || (!$isUrl && !$isInput) || strpos($line, '<?php print ') === false) {
			continue;
		}
		$count++;
		$output = render_output_line($line, $payload);
		if ($isUrl) {
			expect($output)->not->toContain('<');
			expect(substr_count($output, "'"))->toBe(2);
			expect(preg_match("/'([^']*)'/", $output, $match))->toBe(1);
			parse_str(parse_url($match[1], PHP_URL_QUERY), $query);
			expect($query['id'])->toBe($payload);
		} else {
			$document = new \DOMDocument();
			$document->loadHTML('<!doctype html><html><body>' . $output . '</body></html>');
			$inputs = $document->getElementsByTagName('input');
			expect($inputs->length)->toBe(1);
			expect($document->getElementsByTagName('script')->length)->toBe(0);
			expect($document->getElementsByTagName('img')->length)->toBe(0);
			expect($inputs->item(0)->getAttribute('value'))->toBe($payload);
			expect($inputs->item(0)->attributes->length)->toBe(3);
		}
	}
	expect($count)->toBe($expected);
})->with(array(
	'user administration' => array('user_admin.php', 26),
	'group administration' => array('user_group_admin.php', 23),
))->with(array(
	'normal ID' => '42',
	'empty new record' => '',
	'leading zeros' => '0042',
	'attribute boundary' => '\' autofocus onfocus="alert(1)',
	'script boundary' => '</script><script>alert(1)</script>',
	'URL delimiters' => '7&tab=other#fragment%20+ space',
	'entity-like text' => '&#39;&quot;&amp;',
	'backtick' => '` value',
));
