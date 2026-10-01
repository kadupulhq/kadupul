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
 * The csrf-magic output handler adds the token field after POST form start
 * tags. It must read a tag the way the browser does, or a crafted tag can
 * name one action to the handler and another to the browser.
 *
 * Loading csrf-magic.php starts its request handling, so the handler runs in
 * a child process and each page comes back as the handler rewrote it.
 */
function csrf_rewrite_pages(array $pages) {
	$program = <<<'PHP'
function csrf_startup() {
	csrf_conf('rewrite', false);
	csrf_conf('defer', true);
	csrf_conf('auto-session', false);
	csrf_conf('frame-breaker', false);
	csrf_conf('rewrite-js', false);
	csrf_conf('secret', str_repeat('f', 64));
}
require $argv[1] . '/include/vendor/csrf/csrf-magic.php';
session_id('form-rewrite-test-session');
$field = "<input type='hidden' name='__csrf_magic' value=\"" . csrf_get_tokens() . "\" />";
$pages = array();
foreach (json_decode(stream_get_contents(STDIN), true) as $page) {
	$pages[] = csrf_ob_handler('<html><head></head><body>' . $page . '</body></html>', 0);
}
echo json_encode(array('field' => $field, 'pages' => $pages));
PHP;

	$process = proc_open(
		array(PHP_BINARY, '-d', 'error_reporting=-1', '-d', 'display_errors=stderr', '-r', $program, dirname(__DIR__, 4)),
		array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w')),
		$pipes
	);
	expect(is_resource($process))->toBeTrue();
	fwrite($pipes[0], json_encode($pages));
	fclose($pipes[0]);
	$stdout = stream_get_contents($pipes[1]);
	$stderr = stream_get_contents($pipes[2]);
	fclose($pipes[1]);
	fclose($pipes[2]);
	expect(proc_close($process))->toBe(0)
		->and($stderr)->toBe('');

	$result = json_decode($stdout, true, 512, JSON_THROW_ON_ERROR);
	expect($result['field'])->toContain("value=\"sid:");
	foreach ($result['pages'] as $index => $page) {
		expect($page)->toStartWith('<html><head></head><body>')->toEndWith('</body></html>');
		$result['pages'][$index] = substr($page, 25, -14);
	}

	return $result;
}

test('same-origin POST forms get the token field after the start tag', function ($tag) {
	$result = csrf_rewrite_pages(array($tag . '</form>'));

	expect($result['pages'][0])->toBe($tag . $result['field'] . '</form>');
})->with(array(
	'missing action'                 => array("<form method='post'>"),
	'empty action'                   => array("<form method='post' action=''>"),
	'valueless action'               => array("<form method='post' action>"),
	'relative file'                  => array("<form method='post' action='graphs.php'>"),
	'query string'                   => array("<form method='post' action='graphs.php?action=edit&amp;id=1'>"),
	'query only'                     => array("<form method='post' action='?action=save'>"),
	'absolute path'                  => array("<form method='post' action='/cacti/graphs.php'>"),
	'at sign in path'                => array("<form method='post' action='/@other.example/'>"),
	'colon after slash'              => array("<form method='post' action='./a:b'>"),
	'upper case'                     => array("<FORM METHOD='POST' ACTION='graphs.php'>"),
	'double quotes'                  => array('<form method="post" action="graphs.php">'),
	'unquoted'                       => array('<form method=post action=graphs.php>'),
	'action first'                   => array("<form action='graphs.php' id='chk' method='post'>"),
	'greater-than in a quoted value' => array("<form method='post' title='a>b' action='graphs.php'>"),
	'cacti form start'               => array("<form class='cactiForm' method='post' autocomplete='off' action='host.php' name='chk'>"),
));

test('POST forms whose action may leave this origin get no token', function ($tag) {
	$result = csrf_rewrite_pages(array($tag . '</form>'));

	expect($result['pages'][0])->toBe($tag . '</form>');
})->with(array(
	'absolute other host'                  => array("<form method='post' action='https://evil.example/collect'>"),
	'protocol-relative other host'         => array("<form method='post' action='//evil.example/collect'>"),
	'userinfo'                             => array("<form method='post' action='//cacti.example@evil.example/'>"),
	'scheme without slashes'               => array("<form method='post' action='https:evil.example'>"),
	'javascript scheme'                    => array("<form method='post' action='javascript:void(0)'>"),
	'backslashes'                          => array("<form method='post' action='\\\\evil.example\\collect'>"),
	'slash backslash'                      => array("<form method='post' action='/\\evil.example/'>"),
	'leading space'                        => array("<form method='post' action=' //evil.example/'>"),
	'tab inside'                           => array("<form method='post' action='/\t/evil.example/'>"),
	'leading control character'            => array("<form method='post' action='\x01//evil.example/'>"),
	'numeric references'                   => array("<form method='post' action='&#47;&#47;evil.example/'>"),
	'numeric references without semicolon' => array("<form method='post' action='&#47&#x2f;evil.example/'>"),
	'named references'                     => array("<form method='post' action='&sol;&sol;evil.example/'>"),
	'newline reference'                    => array("<form method='post' action='&NewLine;//evil.example/'>"),
	'tab reference'                        => array("<form method='post' action='/&Tab;/evil.example/'>"),
	'colon reference'                      => array("<form method='post' action='https&colon;//evil.example/'>"),
	'action text inside another attribute' => array("<form method='post' title=\" action='graphs.php'\" action='https://evil.example/'>"),
	'greater-than before the action'       => array("<form method='post' title='a>b' action='https://evil.example/'>"),
	'duplicate action, foreign first'      => array("<form method='post' action='https://evil.example/' action='graphs.php'>"),
	'same host left to the browser script' => array("<form method='post' action='https://cacti.example/cacti/graphs.php'>"),
));

test('forms that are not POST are unchanged', function ($tag) {
	$result = csrf_rewrite_pages(array($tag . '</form>'));

	expect($result['pages'][0])->toBe($tag . '</form>');
})->with(array(
	'GET'                       => array("<form method='get' action='graphs.php'>"),
	'no method'                 => array("<form action='graphs.php'>"),
	'post in another attribute' => array("<form method='get' title=\"method='post'\" action='graphs.php'>"),
	'duplicate method, get first' => array("<form method='get' method='post' action='graphs.php'>"),
	'post with a trailing space' => array("<form method='post ' action='graphs.php'>"),
	'other element'             => array("<formula method='post' action='graphs.php'>"),
));

test('a base URL on another origin withholds the token from relative actions', function () {
	$forms  = "<form method='post' action='graphs.php'></form><form method='post'></form>";
	$result = csrf_rewrite_pages(array(
		"<base href='https://evil.example/'>" . $forms,
		"<base href='//evil.example/'>" . $forms,
		"<base href='/cacti/'>" . $forms,
		"<base target='_blank'>" . $forms,
	));
	$field = $result['field'];
	$local = "<form method='post' action='graphs.php'>" . $field . "</form><form method='post'>" . $field . '</form>';

	// A missing action still submits to the document URL, not to the base.
	expect($result['pages'][0])->toBe("<base href='https://evil.example/'><form method='post' action='graphs.php'></form><form method='post'>" . $field . '</form>')
		->and($result['pages'][1])->toBe("<base href='//evil.example/'><form method='post' action='graphs.php'></form><form method='post'>" . $field . '</form>')
		->and($result['pages'][2])->toBe("<base href='/cacti/'>" . $local)
		->and($result['pages'][3])->toBe("<base target='_blank'>" . $local);
});

test('an unterminated form tag gets no token and the page is kept', function () {
	$result = csrf_rewrite_pages(array(
		"<form method='post' action='graphs.php' title='x",
		'<form ' . str_repeat('a', 2000000),
	));

	expect($result['pages'][0])->toBe("<form method='post' action='graphs.php' title='x")
		->and($result['pages'][1])->toBe('<form ' . str_repeat('a', 2000000));
});

/*
 * {F} marks where the handler must insert the token field. A form tag in a
 * comment or in raw text is not a form, and a form start tag inside an open
 * form is dropped by the browser, so its controls, and any field added after
 * it, join the outer form.
 */
test('form tags are read only where the browser parses markup', function ($expected) {
	$result = csrf_rewrite_pages(array(str_replace('{F}', '', $expected)));

	expect($result['pages'][0])->toBe(str_replace('{F}', $result['field'], $expected));
})->with(array(
	'nested in a cross-origin form'      => array("<form method='post' action='https://evil.example/'><form method='post'><button>x</button></form>"),
	'textarea in a cross-origin form'    => array("<form method='post' action='https://evil.example/'><textarea name='x'><form method='post'></textarea></form>"),
	'unclosed textarea'                  => array("<form method='post' action='https://evil.example/'><textarea name='x'><p>page</p><form method='post' action='host.php'><input name='z'></form>"),
	'end tag text inside textarea'       => array("<form method='post' action='//evil.example/'><textarea></form></textarea><form method='post'></form>"),
	'end tag text inside an attribute'   => array("<form method='post' action='//evil.example/'><p title='</form>'><form method='post'></form>"),
	'end tag inside select'              => array("<form method='post' action='//evil.example/'><select></form></select><form method='post'></form>"),
	'form tag text inside an attribute'  => array("<p title=\"<form method='post' action='//evil.example/'>\"></p><form method='post'>{F}</form>"),
	'closed forms in sequence'           => array("<form method='post'>{F}</form><form method='post' action='graphs.php'>{F}</form>"),
	'textarea'                           => array("<textarea><form method='post'></textarea><form method='post'>{F}</form>"),
	'title'                              => array("<title><form method='post'></title><form method='post'>{F}</form>"),
	'script'                             => array("<script>var f = '<form method=\"post\">';</script><form method='post'>{F}</form>"),
	'style'                              => array("<style><form method='post'></style><form method='post'>{F}</form>"),
	'xmp'                                => array("<xmp><form method='post'></xmp><form method='post'>{F}</form>"),
	'iframe'                             => array("<iframe><form method='post'></iframe><form method='post'>{F}</form>"),
	'noembed'                            => array("<noembed><form method='post'></noembed><form method='post'>{F}</form>"),
	'noframes'                           => array("<noframes><form method='post'></noframes><form method='post'>{F}</form>"),
	'upper-case end tag with attributes' => array("<TEXTAREA rows=2><form method='post'></TEXTAREA title='>'><form method='post'>{F}</form>"),
	'end tag name prefix does not close' => array("<textarea></textareax><form method='post'></textarea><form method='post'>{F}</form>"),
	'comment'                            => array("<!-- <form method='post'> --><form method='post'>{F}</form>"),
	'comment closed by --!>'             => array("<!-- <form method='post'> --!><form method='post'>{F}</form>"),
	'abrupt empty comment'               => array("<!--><form method='post'>{F}</form>"),
	'abrupt dash comment'                => array("<!---><form method='post'>{F}</form>"),
	'bogus comment'                      => array("<?x <form method='post'>?><form method='post'>{F}</form>"),
	'doctype'                            => array("<!DOCTYPE html><form method='post'>{F}</form>"),
	'template forms'                     => array("<template><form method='post'>{F}</form></template><form method='post'>{F}</form>"),
	'unterminated textarea'              => array("<textarea><form method='post'>"),
	'unterminated comment'               => array("<!-- <form method='post'>"),
	'escaped script'                     => array("<script><!--<script></script></form></script><form method='post'></form>"),
	'noscript'                           => array("<noscript></noscript><form method='post'></form>"),
	'svg'                                => array("<svg></svg><form method='post'></form>"),
	'cdata'                              => array("<![CDATA[x]]><form method='post'></form>"),
));

test('only a base element the browser parses decides where relative actions go', function ($expected) {
	$result = csrf_rewrite_pages(array(str_replace('{F}', '', $expected)));

	expect($result['pages'][0])->toBe(str_replace('{F}', $result['field'], $expected));
})->with(array(
	'base hidden in textarea before a real one' => array("<textarea><base x='</textarea><base href='https://evil.example/'><a x='>'></a><form method=post action=x.php></form><form method=post>{F}</form>"),
	'base hidden in comment before a real one'  => array("<!-- <base x=' --><base href='https://evil.example/'><b x='' --><form method=post action=x.php></form><form method=post>{F}</form>"),
	'unclosed base counts as another origin'    => array("<form method=post action=x.php></form><form method=post>{F}</form><base href='/cacti/"),
	'base after the scan stops'                 => array("<form method=post action=x.php></form><svg></svg><base href='/cacti/'>"),
	'base text in textarea only'                => array("<textarea><base href='https://evil.example/'></textarea><form method=post action=x.php>{F}</form>"),
	'base text in a comment only'               => array("<!-- <base href='https://evil.example/'> --><form method=post action=x.php>{F}</form>"),
	'local base'                                => array("<base href='/cacti/'><form method=post action=x.php>{F}</form>"),
));

test('inline scripts that build a POST form are left intact', function () {
	// utilities.php builds its POST form in an inline script; a field added
	// inside the quoted string ended the string and broke the script.
	$script = "<script type='text/javascript'>\n\$('<form method=\"post\"></form>')\n\t.attr('action', \$(this).data('link'));\n</script>";
	$result = csrf_rewrite_pages(array($script));

	expect($result['pages'][0])->toBe($script);
});
