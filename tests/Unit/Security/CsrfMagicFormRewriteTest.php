<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

// Runs the installed csrf-magic.php output handler in a child process, since
// loading the library starts its request handling, and returns each page as
// the handler rewrote it together with the field it inserts.
function rewriteCsrfMagicPages(array $pages): array
{
    $program = <<<'PHP'
function csrf_startup() {
    csrf_conf('rewrite', false);
    csrf_conf('defer', true);
    csrf_conf('auto-session', false);
    csrf_conf('frame-breaker', false);
    csrf_conf('secret', 'isolated-form-rewrite-test-secret');
}
require $argv[1] . '/include/vendor/csrf/csrf-magic.php';
session_id('form-rewrite-test-session');
$field = "<input type='hidden' name='__csrf_magic' value=\"" . csrf_get_tokens() . "\" />";
$pages = array();
foreach (json_decode($argv[2], true) as $page) {
    $pages[] = csrf_ob_handler('<html><head></head><body>' . $page . '</body></html>', 0);
}
echo json_encode(array('field' => $field, 'pages' => $pages));
PHP;

    $process = proc_open(
        array(PHP_BINARY, '-r', $program, dirname(__DIR__, 3), json_encode($pages)),
        array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')),
        $pipes
    );
    expect(is_resource($process))->toBeTrue();
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    expect(proc_close($process))->toBe(0)
        ->and($stderr)->toBe('');

    $result = json_decode($stdout, true, 512, JSON_THROW_ON_ERROR);
    foreach ($result['pages'] as $index => $page) {
        expect($page)->toStartWith('<html><head></head><body>')->toEndWith('</body></html>');
        $result['pages'][$index] = substr($page, 25, -14);
    }

    return $result;
}

test('same-origin POST forms get the token field after the start tag', function (string $tag) {
    $result = rewriteCsrfMagicPages(array($tag . '</form>'));

    expect($result['pages'][0])->toBe($tag . $result['field'] . '</form>');
})->with(array(
    'missing action' => array('<form method="post">'),
    'empty action' => array('<form method="post" action="">'),
    'valueless action' => array('<form method="post" action>'),
    'relative file' => array('<form method="post" action="graphs.php">'),
    'query string' => array("<form method='post' action='graphs.php?action=edit&amp;id=1'>"),
    'query only' => array('<form method="post" action="?action=save">'),
    'absolute path' => array('<form method="post" action="/kadupul/graphs.php">'),
    'at sign in path' => array('<form method="post" action="/@other.example/">'),
    'colon after slash' => array('<form method="post" action="./a:b">'),
    'upper case' => array('<FORM METHOD="POST" ACTION="graphs.php">'),
    'unquoted' => array('<form method=post action=graphs.php>'),
    'action first' => array('<form action="graphs.php" id="chk" method="post">'),
    'greater-than in a quoted value' => array('<form method="post" title="a>b" action="graphs.php">'),
));

test('POST forms whose action may leave this origin get no token', function (string $tag) {
    $result = rewriteCsrfMagicPages(array($tag . '</form>'));

    expect($result['pages'][0])->toBe($tag . '</form>');
})->with(array(
    'absolute other host' => array('<form method="post" action="https://evil.example/collect">'),
    'protocol-relative other host' => array('<form method="post" action="//evil.example/collect">'),
    'protocol-relative upper case' => array('<FORM METHOD="POST" ACTION="//EVIL.EXAMPLE/">'),
    'userinfo' => array('<form method="post" action="//kadupul.example@evil.example/">'),
    'scheme without slashes' => array('<form method="post" action="https:evil.example">'),
    'javascript scheme' => array('<form method="post" action="javascript:void(0)">'),
    'data scheme' => array('<form method="post" action="data:text/html,x">'),
    'backslashes' => array('<form method="post" action="\\\\evil.example\\collect">'),
    'slash backslash' => array('<form method="post" action="/\\evil.example/">'),
    'leading space' => array('<form method="post" action=" //evil.example/">'),
    'tab inside' => array("<form method=\"post\" action=\"/\t/evil.example/\">"),
    'newline inside' => array("<form method=\"post\" action=\"/\n/evil.example/\">"),
    'leading control character' => array("<form method=\"post\" action=\"\x01//evil.example/\">"),
    'numeric references' => array('<form method="post" action="&#47;&#47;evil.example/">'),
    'numeric references without semicolons' => array('<form method="post" action="&#47&#x2f;evil.example/">'),
    'named references' => array('<form method="post" action="&sol;&sol;evil.example/">'),
    'colon reference' => array('<form method="post" action="https&colon;//evil.example/">'),
    'tab reference' => array('<form method="post" action="/&Tab;/evil.example/">'),
    'action text inside another attribute' => array('<form method="post" title=" action=graphs.php" action="https://evil.example/">'),
    'data-action before action' => array('<form method="post" data-action="graphs.php" action="//evil.example/">'),
    'duplicate action' => array('<form method="post" action="https://evil.example/" action="graphs.php">'),
    'same host left to the browser script' => array('<form method="post" action="https://kadupul.example/kadupul/graphs.php">'),
    'protocol-relative same host left to the browser script' => array('<form method="post" action="//kadupul.example/kadupul/graphs.php">'),
));

test('forms that are not POST are unchanged', function (string $tag) {
    $result = rewriteCsrfMagicPages(array($tag . '</form>'));

    expect($result['pages'][0])->toBe($tag . '</form>');
})->with(array(
    'GET' => array('<form method="get" action="graphs.php">'),
    'no method' => array('<form action="graphs.php">'),
    'post in another attribute' => array('<form method="get" data-method="post" action="graphs.php">'),
    'post with a trailing space' => array('<form method="post " action="graphs.php">'),
    'other element' => array('<formula method="post" action="graphs.php">'),
));

test('a base URL on another origin withholds the token from relative actions', function () {
    $forms = '<form method="post" action="graphs.php"></form><form method="post"></form>';
    $result = rewriteCsrfMagicPages(array(
        '<base href="https://evil.example/">' . $forms,
        '<base href="//evil.example/">' . $forms,
        '<base href="/kadupul/">' . $forms,
        '<base target="_blank">' . $forms,
    ));
    $field = $result['field'];
    $local = '<form method="post" action="graphs.php">' . $field . '</form><form method="post">' . $field . '</form>';

    // A missing action still submits to the document URL, not to the base.
    expect($result['pages'][0])->toBe('<base href="https://evil.example/"><form method="post" action="graphs.php"></form><form method="post">' . $field . '</form>')
        ->and($result['pages'][1])->toBe('<base href="//evil.example/"><form method="post" action="graphs.php"></form><form method="post">' . $field . '</form>')
        ->and($result['pages'][2])->toBe('<base href="/kadupul/">' . $local)
        ->and($result['pages'][3])->toBe('<base target="_blank">' . $local);
});

test('an unterminated form tag gets no token', function () {
    $result = rewriteCsrfMagicPages(array('<form method="post" action="graphs.php" title="x'));

    expect($result['pages'][0])->toBe('<form method="post" action="graphs.php" title="x');
});

// {F} marks where the handler must insert the token field.
test('form tags are read only where the browser parses markup', function (string $expected) {
    $result = rewriteCsrfMagicPages(array(str_replace('{F}', '', $expected)));

    expect($result['pages'][0])->toBe(str_replace('{F}', $result['field'], $expected));
})->with(array(
    'nested in a cross-origin form' => array('<form method="post" action="https://evil.example/"><form method="post"><button>x</button></form>'),
    'textarea in a cross-origin form' => array('<form method="post" action="https://evil.example/"><textarea name="x"><form method="post"></textarea></form>'),
    'end tag text inside textarea' => array('<form method="post" action="//evil.example/"><textarea></form></textarea><form method="post"></form>'),
    'end tag text inside an attribute' => array('<form method="post" action="//evil.example/"><p title="</form>"><form method="post"></form>'),
    'foreign form pointer retained inside select' => array('<select><form method="post" action="https://other.example/"></select><form method="post"></form>'),
    'form pointer retained inside select' => array('<select><form method="post"></select><form method="post"></form>'),
    'doctype ends at quoted greater-than' => array('<form method="post" action="//evil.example/"><!DOCTYPE html PUBLIC "></form>" ""><form method="post">{F}<button>Save</button></form>'),
    'end tag inside select' => array('<form method="post" action="//evil.example/"><select></form></select><form method="post"></form>'),
    'closed forms in sequence' => array('<form method="post">{F}</form><form method="post" action="graphs.php">{F}</form>'),
    'textarea' => array('<textarea><form method="post"></textarea><form method="post">{F}</form>'),
    'title' => array('<title><form method="post"></title><form method="post">{F}</form>'),
    'script' => array('<script>var f = \'<form method="post">\';</script><form method="post">{F}</form>'),
    'style' => array('<style><form method="post"></style><form method="post">{F}</form>'),
    'xmp' => array('<xmp><form method="post"></xmp><form method="post">{F}</form>'),
    'iframe' => array('<iframe><form method="post"></iframe><form method="post">{F}</form>'),
    'noembed' => array('<noembed><form method="post"></noembed><form method="post">{F}</form>'),
    'noframes' => array('<noframes><form method="post"></noframes><form method="post">{F}</form>'),
    'upper-case end tag with attributes' => array('<TEXTAREA rows=2><form method="post"></TEXTAREA title=">"><form method="post">{F}</form>'),
    'end tag name prefix does not close' => array('<textarea></textareax><form method="post"></textarea><form method="post">{F}</form>'),
    'comment' => array('<!-- <form method="post"> --><form method="post">{F}</form>'),
    'comment closed by --!>' => array('<!-- <form method="post"> --!><form method="post">{F}</form>'),
    'abrupt empty comment' => array('<!--><form method="post">{F}</form>'),
    'abrupt dash comment' => array('<!---><form method="post">{F}</form>'),
    'bogus comment' => array('<?x <form method="post">?><form method="post">{F}</form>'),
    'doctype' => array('<!DOCTYPE html><form method="post">{F}</form>'),
    'template forms' => array('<template><form method="post">{F}</form></template><form method="post">{F}</form>'),
    'unterminated textarea' => array('<textarea><form method="post">'),
    'unterminated comment' => array('<!-- <form method="post">'),
    'escaped script' => array('<script><!--<script></script></form></script><form method="post"></form>'),
    'noscript' => array('<noscript></noscript><form method="post"></form>'),
    'svg' => array('<svg></svg><form method="post"></form>'),
    'cdata' => array('<![CDATA[x]]><form method="post"></form>'),
));

test('only a base element the browser parses decides where relative actions go', function (string $expected) {
    $result = rewriteCsrfMagicPages(array(str_replace('{F}', '', $expected)));

    expect($result['pages'][0])->toBe(str_replace('{F}', $result['field'], $expected));
})->with(array(
    'base hidden in textarea before a real one' => array("<textarea><base x='</textarea><base href='https://evil.example/'><a x='>'></a><form method=post action=x.php></form><form method=post>{F}</form>"),
    'base hidden in comment before a real one' => array("<!-- <base x=' --><base href='https://evil.example/'><b x='' --><form method=post action=x.php></form><form method=post>{F}</form>"),
    'unclosed base' => array("<form method=post action=x.php></form><form method=post>{F}</form><base href='/kadupul/"),
    'base text in textarea only' => array("<textarea><base href='https://evil.example/'></textarea><form method=post action=x.php>{F}</form>"),
));

// Runs the whole output handler with the browser script enabled, as
// include/csrf.php configures it, and returns the page and the token field.
function renderCsrfMagicPage(string $page): array
{
    $program = <<<'PHP'
class CactiSecureHeaders { public static function getNonceAttribute() { return 'nonce="fixture"'; } }
function csrf_startup() {
    csrf_conf('rewrite', false);
    csrf_conf('defer', true);
    csrf_conf('auto-session', false);
    csrf_conf('frame-breaker', false);
    csrf_conf('secret', 'isolated-form-rewrite-test-secret');
    csrf_conf('rewrite-js', '/kadupul/include/vendor/csrf/csrf-magic.js');
}
require $argv[1] . '/include/vendor/csrf/csrf-magic.php';
session_id('form-rewrite-test-session');
$field = "<input type='hidden' name='__csrf_magic' value=\"" . csrf_get_tokens() . "\" />";
echo json_encode(array('field' => $field, 'page' => csrf_ob_handler($argv[2], 0)));
PHP;

    $process = proc_open(
        array(PHP_BINARY, '-r', $program, dirname(__DIR__, 3), $page),
        array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')),
        $pipes
    );
    expect(is_resource($process))->toBeTrue();
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    expect(proc_close($process))->toBe(0)
        ->and($stderr)->toBe('');

    return json_decode($stdout, true, 512, JSON_THROW_ON_ERROR);
}

// The handler adds the CsrfMagic.end() call before </body>. Markup left open
// after a form turns that call into text the browser never runs, while the
// form keeps the field the server added. Only the submit check that
// csrf-magic.js installs from the head can then withhold the token from a
// cross-origin formaction, including a button associated with form="f".
test('markup left open after a form keeps the CsrfMagic.end() call from running', function (string $open, string $close) {
    $form = '<form method="post" id="f"><button formaction="//evil.example/collect">x</button></form>'
        . '<button form="f" formaction="https://evil.example/collect">y</button>';
    $result = renderCsrfMagicPage('<html><head></head><body>' . $form . $open . 'text</body></html>');
    $page = $result['page'];
    $script = strpos($page, 'src="/kadupul/include/vendor/csrf/csrf-magic.js"');
    $field = strpos($page, '<form method="post" id="f">' . $result['field']);
    $opened = strpos($page, $open);
    $end = strpos($page, 'CsrfMagic.end();');

    expect($script)->toBeInt()
        ->and($field)->toBeInt()->toBeGreaterThan($script)
        ->and($opened)->toBeGreaterThan($field)
        ->and($end)->toBeGreaterThan($opened);
    if ($close !== '') {
        expect(stripos(substr($page, $opened + strlen($open)), $close))->toBeFalse();
    }
})->with(array(
    'unclosed plaintext' => array('<plaintext>', ''),
    'unclosed textarea' => array('<textarea>', '</textarea'),
    'unclosed title' => array('<title>', '</title'),
    'unclosed xmp' => array('<xmp>', '</xmp'),
    'unclosed comment' => array('<!--', '-->'),
));

test('pages with many scripts take time in proportion to their size', function () {
    $program = <<<'PHP'
function csrf_startup() {
    csrf_conf('rewrite', false);
    csrf_conf('defer', true);
    csrf_conf('auto-session', false);
    csrf_conf('secret', 'isolated-form-rewrite-test-secret');
}
require $argv[1] . '/include/vendor/csrf/csrf-magic.php';
$times = array();
foreach (array(1000, 2000, 64000) as $scripts) {
    $page = "<form method='post'></form>" . str_repeat('<script>var a = 1;</script>', $scripts) . "<form method='post'></form>";
    $start = microtime(true);
    $fields = substr_count(csrf_rewrite_forms($page, '{F}'), '{F}');
    $times[] = array($fields, microtime(true) - $start);
}
echo json_encode($times);
PHP;

    $process = proc_open(
        array(PHP_BINARY, '-r', $program, dirname(__DIR__, 3)),
        array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')),
        $pipes
    );
    expect(is_resource($process))->toBeTrue();
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    expect(proc_close($process))->toBe(0)
        ->and($stderr)->toBe('');

    // The first run warms up. Thirty-two times the scripts should take about
    // thirty-two times as long; an end-tag search that scanned to the end of
    // the page for each script made it over a hundred times slower.
    [, [$small_fields, $small], [$large_fields, $large]] = json_decode($stdout, true, 512, JSON_THROW_ON_ERROR);
    expect($small_fields)->toBe(2)
        ->and($large_fields)->toBe(2)
        ->and($large / $small)->toBeLessThan(80);
});
