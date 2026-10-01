<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * Declarations that browsers silently discard. Each one hid a real defect in a
 * shipped theme, so a match means the author's intent is not what renders.
 */
function theme_css_invalid_declarations(string $css): array
{
    [$blocks, $problems] = theme_css_blocks($css);

    $patterns = [
        '/^#[a-z-]+\s*:/i'                           => 'hash-prefixed property',
        '/^color\s*:\s*show\b/i'                     => 'color: show',
        '/^align-self\s*:\s*left\b/i'                => 'align-self: left',
        '/^font-weight\s*:\s*light\b/i'              => 'font-weight: light',
        '/-moz-use-text-color/i'                     => '-moz-use-text-color',
        '/-webkit-gradient\(\s*left\b/i'             => 'malformed -webkit-gradient()',
        '/-(?:webkit|moz|ms|o)-(?:repeating-)?linear-gradient\(\s*to\b/i' => 'standard direction in prefixed linear-gradient()',
        '/:\s*(?:radial|linear)-gradient\(\s*(?:\d+%\s+\d+%\s*,|left\b)/i' => 'legacy syntax in unprefixed gradient',
        '/^(?:border-color|box-shadow)\s*:[^:]*gradient\(/i' => 'gradient in border-color or box-shadow',
        '/^float\s*:\s*middle\b/i'                   => 'float: middle',
        '/^!important$/i'                            => 'detached !important',
    ];

    foreach ($blocks as $block) {
        foreach (theme_css_declarations($block) as [$declaration, $visible]) {
            $declaration = trim($declaration);

            foreach ($patterns as $pattern => $label) {
                if (preg_match($pattern, $declaration)) {
                    $problems[] = $label . ': ' . $declaration;
                }
            }

            // Values inside strings/functions cannot begin another declaration.
            // Retain the legacy Microsoft filter's namespaced function spelling.
            $visible = preg_replace('/\bprogid:[a-z0-9_.]+/i', 'legacy_filter', trim($visible));
            if (!str_starts_with($visible, '--') && preg_match('/\s+(?:--)?[a-z][a-z-]*\s*:(?!:)/i', $visible)) {
                $problems[] = 'missing semicolon: ' . $declaration;
            }

            if (theme_css_has_unitless_length($declaration)) {
                $problems[] = 'unitless length: ' . $declaration;
            }
        }
    }

    return $problems;
}

/** Recognize comments and block boundaries only outside strings and functions. */
function theme_css_blocks(string $css): array
{
    $blocks = $stack = $problems = [];
    $quote = null;
    $depth = 0;
    for ($i = 0, $length = strlen($css); $i < $length; $i++) {
        $character = $css[$i];
        $next = $css[$i + 1] ?? '';
        $text = $character;
        if ($quote !== null) {
            if ($character === '\\' && $next !== '') {
                $text .= $css[++$i];
            } elseif ($character === $quote) {
                $quote = null;
            }
        } elseif ($character === '"' || $character === "'") {
            $quote = $character;
        } elseif ($character === '\\' && $next !== '') {
            $text .= $css[++$i];
        } elseif ($character === '/' && $next === '*') {
            $end = strpos($css, '*/', $i + 2);
            if ($end === false) {
                $problems[] = 'unclosed comment';
                break;
            }
            $i = $end + 1;
            $text = ' ';
        } elseif ($character === '*' && $next === '/') {
            $problems[] = 'stray */ outside a comment';
            $i++;
            $text = ' ';
        } elseif ($character === '(') {
            $depth++;
        } elseif ($character === ')') {
            $depth--;
        } elseif ($character === '{' && $depth === 0) {
            $stack[] = '';
            continue;
        } elseif ($character === '}' && $depth === 0) {
            if ($stack !== []) {
                $blocks[] = array_pop($stack);
            }
            $text = ' ';
        }
        if ($stack !== []) {
            $stack[array_key_last($stack)] .= $text;
        }
    }
    return [$blocks, $problems];
}

/** Split only top-level semicolons and expose only top-level value text. */
function theme_css_declarations(string $block): array
{
    $declarations = [];
    $original = $visible = '';
    $quote = null;
    $depth = 0;
    for ($i = 0, $length = strlen($block); $i < $length; $i++) {
        $character = $block[$i];
        $original .= $character;
        if ($quote !== null) {
            if ($character === '\\' && $i + 1 < $length) {
                $original .= $block[++$i];
            } elseif ($character === $quote) {
                $quote = null;
            }
            $visible .= ' ';
        } elseif ($character === '"' || $character === "'") {
            $quote = $character;
            $visible .= ' ';
        } elseif ($character === '(') {
            $depth++;
            $visible .= ' ';
        } elseif ($character === ')') {
            $depth--;
            $visible .= ' ';
        } elseif ($character === ';' && $depth === 0) {
            $declarations[] = [substr($original, 0, -1), $visible];
            $original = $visible = '';
        } else {
            $visible .= $depth === 0 ? $character : ' ';
        }
    }
    if (trim($original) !== '') {
        $declarations[] = [$original, $visible];
    }
    return $declarations;
}

/**
 * The pages are served with <!DOCTYPE html>, so a bare nonzero number where a
 * length belongs is dropped rather than read as pixels the way quirks mode did.
 */
function theme_css_has_unitless_length(string $declaration): bool
{
    $lengths = '(?:padding|margin)(?:-(?:top|right|bottom|left))?|(?:min-|max-)?(?:width|height)'
        . '|top|right|bottom|left|font-size|text-indent|letter-spacing|border-radius'
        . '|border(?:-(?:top|right|bottom|left))?-width|outline-width|gap';

    if (!preg_match('/^(?:' . $lengths . ')\s*:(.*)$/is', $declaration, $match)) {
        return false;
    }

    $value = preg_replace('/!\s*important\s*$/i', '', $match[1]);

    foreach (preg_split('/\s+/', trim($value)) as $token) {
        if (preg_match('/^[-+]?(?:\d*\.)?\d+$/', $token) && (float) $token !== 0.0) {
            return true;
        }
    }

    return false;
}

function theme_css_files(): array
{
    $themes = dirname(__DIR__, 3) . '/include/themes';
    $files = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($themes));

    foreach ($iterator as $file) {
        if ($file->getExtension() === 'css') {
            $files[] = $file->getPathname();
        }
    }

    sort($files);

    return $files;
}

/**
 * Midwinter partials use a legacy MD5 or current v=SHA-256 query. A hash that no
 * longer matches the file lets browsers and proxies keep serving the old copy.
 */
function midwinter_stale_imports(string $css, string $directory): array
{
    $stale = [];

    preg_match_all('/@import url\([\'"]([^?\'"]+)(?:\?([^\'"]*))?[\'"]\)/', $css, $imports, PREG_SET_ORDER);

    foreach ($imports as $import) {
        $query = $import[2] ?? '';
        $algorithm = str_starts_with($query, 'v=') ? 'sha256' : 'md5';
        $expected = ($algorithm === 'sha256' ? 'v=' : '') . hash_file($algorithm, $directory . '/' . $import[1]);

        if ($query !== $expected) {
            $stale[] = $import[1];
        }
    }

    return $stale;
}

it('ships theme stylesheets without declarations browsers discard', function (): void {
    $files = theme_css_files();

    expect($files)->not->toBeEmpty();

    $problems = [];

    foreach ($files as $file) {
        $relative = substr($file, strlen(dirname(__DIR__, 3)) + 1);

        foreach (theme_css_invalid_declarations(file_get_contents($file)) as $problem) {
            $problems[] = $relative . ': ' . $problem;
        }
    }

    expect($problems)->toBe([]);
});

it('flags each discarded declaration pattern', function (string $css, string $label): void {
    $problems = theme_css_invalid_declarations($css);

    expect($problems)->toHaveCount(1)
        ->and($problems[0])->toStartWith($label);
})->with([
    'stray comment end'   => [".a {\n\tborder-color: #222;\n\t*/\n}", 'stray */'],
    'hash property'       => ['.a { #z-index: 2; }', 'hash-prefixed property'],
    'color show'          => ['.a:hover { color: show; }', 'color: show'],
    'align-self left'     => ['.a { align-self: left; }', 'align-self: left'],
    'font-weight light'   => ['.a { font-weight: light; }', 'font-weight: light'],
    'moz text color'      => ['.a { border-color: #999 -moz-use-text-color; }', '-moz-use-text-color'],
    'webkit gradient'     => ['.a { background: -webkit-gradient(left top, left bottom, from(#fff), to(#000)); }', 'malformed -webkit-gradient()'],
    'detached important'  => ['.a { width: 11.75px; !important; }', 'detached !important'],
    'webkit to keyword'   => ['.a { background-image: -webkit-linear-gradient(to right, black, white); }', 'standard direction'],
    'moz to keyword'      => ['.a { background-image: -moz-linear-gradient(to right, black, white) !important; }', 'standard direction'],
    'legacy radial'       => ['.a { background-image: radial-gradient(85% 145%, ellipse cover, #fff 1%, #000 65%); }', 'legacy syntax'],
    'legacy linear'       => ['.a { background-image: linear-gradient(left, #fff 0%, #000 100%) !important; }', 'legacy syntax'],
    'gradient border'     => ['.a { border-color: linear-gradient(to bottom, #252426 0%, #0c0d0d 100%); }', 'gradient in border-color'],
    'gradient shadow'     => ['.a { box-shadow: -moz-linear-gradient(top, #45484d 100%, #000 100%); }', 'gradient in border-color'],
    'float middle'        => ['.a { float: middle; }', 'float: middle'],
    'missing semicolon'   => [".a {\n\tbox-shadow: 0 0 18px #00438C, 0 0 5px #00438C\n\topacity: 1.0;\n}", 'missing semicolon'],
    'same-line semicolon' => ['.a { color: red opacity: .5; }', 'missing semicolon'],
    'missing before var'  => [".a {\n    padding: 6px\n\tborder: 1px solid var(--border-color);\n}", 'missing semicolon'],
    'unitless padding'    => ['.moveArrowNone { padding-left: 8.75; }', 'unitless length'],
    'unitless shorthand'  => ['.a { margin: 0 4 0 0 !important; }', 'unitless length'],
    'unitless width'      => ['.a { width: 12; }', 'unitless length'],
]);

it('accepts quoted comment markers without removing declaration text', function (string $css): void {
    expect(theme_css_invalid_declarations($css))->toBe([]);
})->with([
    '.a { content: "*/"; }',
    '.a { content: "/* text */"; }',
    '.a { content: "escaped \\" */"; }',
]);

it('checks invalid declarations after quoted braces and comments', function (string $css): void {
    expect(theme_css_invalid_declarations($css))->toHaveCount(1)
        ->and(theme_css_invalid_declarations($css)[0])->toStartWith('color: show');
})->with([
    '.a { content: "}"; color: show; }',
    '.a { content: "{"; color: show; }',
    '.a { content: "/*"; color: show; } /* actual comment */',
    '@media screen { .a { content: "}"; color: show; } }',
]);

it('accepts valid declarations that look like the invalid ones', function (): void {
    $css = <<<'CSS'
        /* a comment
           that spans lines */
        #main:hover, #tabs .show { color: #fff; }
        .a { background: url('a.png#frag') no-repeat center; align-self: flex-start; }
        .b { font-weight: lighter; width: 11.75px !important; }
        .c { background-image: -webkit-gradient(linear, left 0%, left 100%, from(#fff), to(#000)); }
        .e { background-image: -webkit-linear-gradient(left, #fff, #000); background-image: radial-gradient(ellipse at 85% 145%, #fff, #000); }
        .f { box-shadow: 0 0 1px #2196f3; float: left; }
        @media screen { .d { color: white; } }
        .g { padding-left: 8.75px; margin: 0 auto; top: 0; width: 0.0; line-height: 1.5; z-index: 2; opacity: 1.0; }
        .h {
            background: -webkit-gradient(linear, left top, left bottom,
                color-stop(0%, #fff), color-stop(100%, #000));
            filter: progid:DXImageTransform.Microsoft.gradient(startColorstr='#ffffff', endColorstr='#000000');
            src: url('a.woff2') format('woff2'),
                url('data:font/woff;base64,AA==') format('woff');
        }
        .i { box-shadow: 0 0 18px #00438C, 0 0 5px #00438C }
        .j { content: 'name; opacity: value'; background: url('https://example.test/a:b.png'); }
        CSS;

    expect(theme_css_invalid_declarations($css))->toBe([]);
});

it('keeps midwinter import hashes in step with the imported files', function (): void {
    $directory = dirname(__DIR__, 3) . '/include/themes/midwinter';

    expect(midwinter_stale_imports(file_get_contents($directory . '/main.css'), $directory))->toBe([]);
});

it('reports a midwinter import whose hash is wrong or missing', function (): void {
    $directory = dirname(__DIR__, 3) . '/include/themes/midwinter';
    $current = md5_file($directory . '/css/pre/fonts.css');
    $css = "@import url('./css/pre/fonts.css?{$current}');\n"
        . "@import url('./css/pre/colors.css?00000000000000000000000000000000');\n"
        . "@import url('./css/pre/colors.css?not-a-hash');\n"
        . "@import url('./css/pre/colors.css?v=bad');\n"
        . "@import url(\"./css/pre/keyframes.css\");\n";

    expect(midwinter_stale_imports($css, $directory))
        ->toBe(['./css/pre/colors.css', './css/pre/colors.css', './css/pre/colors.css', './css/pre/keyframes.css']);
});
