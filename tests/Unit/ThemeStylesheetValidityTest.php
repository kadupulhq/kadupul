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
    $problems = [];
    $css = preg_replace('~/\*.*?\*/~s', '', $css);

    if (str_contains($css, '*/')) {
        $problems[] = 'stray */ outside a comment';
    }

    $patterns = [
        '/^#[a-z-]+\s*:/i'                           => 'hash-prefixed property',
        '/^color\s*:\s*show\b/i'                     => 'color: show',
        '/^align-self\s*:\s*left\b/i'                => 'align-self: left',
        '/^font-weight\s*:\s*light\b/i'              => 'font-weight: light',
        '/-moz-use-text-color/i'                     => '-moz-use-text-color',
        '/-webkit-gradient\(\s*left\b/i'             => 'malformed -webkit-gradient()',
        '/-webkit-linear-gradient\(\s*to\b/i'        => 'standard direction in -webkit-linear-gradient()',
        '/:\s*(?:radial|linear)-gradient\(\s*(?:\d+%\s+\d+%\s*,|left\b)/i' => 'legacy syntax in unprefixed gradient',
        '/^(?:border-color|box-shadow)\s*:[^:]*gradient\(/i' => 'gradient in border-color or box-shadow',
        '/^float\s*:\s*middle\b/i'                   => 'float: middle',
        '/^!important$/i'                            => 'detached !important',
    ];

    preg_match_all('/\{([^{}]*)\}/', $css, $blocks);

    foreach ($blocks[1] as $block) {
        foreach (explode(';', $block) as $declaration) {
            $declaration = trim($declaration);

            foreach ($patterns as $pattern => $label) {
                if (preg_match($pattern, $declaration)) {
                    $problems[] = $label . ': ' . $declaration;
                }
            }
        }
    }

    return $problems;
}

function theme_css_authored_files(): array
{
    $themes = dirname(__DIR__, 2) . '/include/themes';
    $files = glob($themes . '/*/main.css');
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($themes . '/midwinter/css'));

    foreach ($iterator as $file) {
        if ($file->getExtension() === 'css') {
            $files[] = $file->getPathname();
        }
    }

    sort($files);

    return $files;
}

/**
 * Midwinter loads its partials through @import url('file?md5'). A hash that no
 * longer matches the file lets browsers and proxies keep serving the old copy.
 */
function midwinter_stale_imports(string $css, string $directory): array
{
    $stale = [];

    preg_match_all('/@import url\([\'"]([^?\'"]+)(?:\?([0-9a-f]*))?[\'"]\)/', $css, $imports, PREG_SET_ORDER);

    foreach ($imports as $import) {
        $expected = md5_file($directory . '/' . $import[1]);

        if (($import[2] ?? '') !== $expected) {
            $stale[] = $import[1];
        }
    }

    return $stale;
}

it('ships theme stylesheets without declarations browsers discard', function (): void {
    $files = theme_css_authored_files();

    expect($files)->not->toBeEmpty();

    foreach ($files as $file) {
        $relative = substr($file, strlen(dirname(__DIR__, 2)) + 1);

        expect(theme_css_invalid_declarations(file_get_contents($file)))->toBe([], $relative);
    }
});

it('flags each discarded declaration pattern', function (string $css, string $label): void {
    $problems = theme_css_invalid_declarations($css);

    expect($problems)->toHaveCount(1)
        ->and($problems[0])->toStartWith($label);
})->with([
    'stray comment end'   => [".a {\n\tborder-color: #222;\n\t*/\n\tbox-shadow: none;\n}", 'stray */'],
    'hash property'       => ['.a { #z-index: 2; }', 'hash-prefixed property'],
    'color show'          => ['.a:hover { color: show; }', 'color: show'],
    'align-self left'     => ['.a { align-self: left; }', 'align-self: left'],
    'font-weight light'   => ['.a { font-weight: light; }', 'font-weight: light'],
    'moz text color'      => ['.a { border-color: #999 -moz-use-text-color; }', '-moz-use-text-color'],
    'webkit gradient'     => ['.a { background: -webkit-gradient(left top, left bottom, from(#fff), to(#000)); }', 'malformed -webkit-gradient()'],
    'detached important'  => ['.a { width: 11.75px; !important; }', 'detached !important'],
    'webkit to keyword'   => ['.a { background-image: -webkit-linear-gradient(to right, black, white); }', 'standard direction'],
    'legacy radial'       => ['.a { background-image: radial-gradient(85% 145%, ellipse cover, #fff 1%, #000 65%); }', 'legacy syntax'],
    'legacy linear'       => ['.a { background-image: linear-gradient(left, #fff 0%, #000 100%) !important; }', 'legacy syntax'],
    'gradient border'     => ['.a { border-color: linear-gradient(to bottom, #252426 0%, #0c0d0d 100%); }', 'gradient in border-color'],
    'gradient shadow'     => ['.a { box-shadow: -moz-linear-gradient(top, #45484d 100%, #000 100%); }', 'gradient in border-color'],
    'float middle'        => ['.a { float: middle; }', 'float: middle'],
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
        CSS;

    expect(theme_css_invalid_declarations($css))->toBe([]);
});

it('keeps midwinter import hashes in step with the imported files', function (): void {
    $directory = dirname(__DIR__, 2) . '/include/themes/midwinter';

    expect(midwinter_stale_imports(file_get_contents($directory . '/main.css'), $directory))->toBe([]);
});

it('reports a midwinter import whose hash is wrong or missing', function (): void {
    $directory = dirname(__DIR__, 2) . '/include/themes/midwinter';
    $current = md5_file($directory . '/css/pre/fonts.css');
    $css = "@import url('./css/pre/fonts.css?{$current}');\n"
        . "@import url('./css/pre/colors.css?00000000000000000000000000000000');\n"
        . "@import url(\"./css/pre/keyframes.css\");\n";

    expect(midwinter_stale_imports($css, $directory))
        ->toBe(['./css/pre/colors.css', './css/pre/keyframes.css']);
});
