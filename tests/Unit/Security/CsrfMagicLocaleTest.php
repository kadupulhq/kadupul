<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

// Kadupul sets LC_CTYPE from the user's language. In a Turkish locale "I"
// does not lower to "i": PHP 8.1's strtolower() turns ACTION into "actIon",
// and the PCRE /i flag stops matching SCRIPT against "script" on later
// versions. The output handler must reach the same decisions in any locale.

// Runs csrf_rewrite_forms() from the installed csrf-magic.php in a child
// process, under tr_TR when $turkish is set, with {F} as the token field.
// Returns null when the child cannot select a Turkish locale.
function rewriteCsrfMagicPagesInLocale(array $pages, bool $turkish): ?array
{
    $program = <<<'PHP'
function csrf_startup() {
    csrf_conf('rewrite', false);
    csrf_conf('defer', true);
    csrf_conf('auto-session', false);
    csrf_conf('frame-breaker', false);
    csrf_conf('secret', 'isolated-locale-test-secret');
}
require $argv[1] . '/include/vendor/csrf/csrf-magic.php';
if ($argv[2] === 'tr' && setlocale(LC_CTYPE, 'tr_TR.UTF-8', 'tr_TR.utf8', 'tr_TR') === false) {
    echo json_encode(null);
    exit;
}
$pages = array();
foreach (json_decode($argv[3], true) as $name => $page) {
    $pages[$name] = csrf_rewrite_forms($page, '{F}');
}
echo json_encode($pages);
PHP;

    $process = proc_open(
        array(PHP_BINARY, '-r', $program, dirname(__DIR__, 3), $turkish ? 'tr' : 'C', json_encode($pages)),
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

// {F} marks where the handler must insert the token field.
function csrfMagicLocalePages(): array
{
    return array(
        'upper-case action to another origin' => '<form method=post ACTION=//evil.example/></form>',
        'upper-case script hides a form end tag' => "<form method=post action=//evil.example/><SCRIPT>'</form>'</SCRIPT><form method=post></form>",
        'upper-case title hides form text' => '<TITLE><form method=post></TITLE><form method=post>{F}</form>',
        'upper-case I in a scheme' => "<form method=post action='XI:evil'></form>",
        'upper-case scheme' => "<form method=post action='HTTPS://evil.example/'></form>",
        'upper-case base to another origin' => "<BASE HREF='//evil.example/'><form method=post action=x.php></form>",
        'upper-case form after a script' => '<SCRIPT>var a = 1;</SCRIPT><FORM METHOD=POST ACTION=graphs.php>{F}</FORM>',
        'upper-case form after a title' => '<TITLE>Kadupul</TITLE><FORM METHOD=POST ACTION=graphs.php>{F}</FORM>',
    );
}

function csrfMagicLocaleMismatches(bool $turkish): ?array
{
    $expected = csrfMagicLocalePages();
    $pages = rewriteCsrfMagicPagesInLocale(array_map(fn(string $page): string => str_replace('{F}', '', $page), $expected), $turkish);
    if ($pages === null) {
        return null;
    }

    $mismatches = array();
    foreach ($expected as $name => $page) {
        if ($pages[$name] !== $page) {
            $mismatches[$name] = $pages[$name];
        }
    }

    return $mismatches;
}

test('tag names, attribute names, methods and schemes are read the same in a Turkish locale', function () {
    $mismatches = csrfMagicLocaleMismatches(true);
    if ($mismatches === null) {
        test()->markTestSkipped('The tr_TR locale is not installed; run this where tr_TR.UTF-8 is generated');
    }

    expect($mismatches)->toBe(array());
});

test('upper-case markup is read the same in the C locale', function () {
    expect(csrfMagicLocaleMismatches(false))->toBe(array());
});

test('the tag walker compares names without locale-dependent functions', function () {
    $source = file_get_contents(dirname(__DIR__, 3) . '/include/vendor/csrf/csrf-magic.php');
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
