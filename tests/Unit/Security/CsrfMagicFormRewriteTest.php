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
