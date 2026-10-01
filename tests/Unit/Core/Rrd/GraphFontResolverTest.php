<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

// bootstrap-unit.php loads only the test runner's autoloader; the application
// autoloader would bring a second PHPUnit into this process.
foreach (array('GraphFontMethod', 'GraphFont', 'GraphFontProfile', 'GraphFontResolver') as $class) {
    require_once dirname(__DIR__, 4) . '/src/Graphing/Domain/Font/' . $class . '.php';
}

use Kadupul\Graphing\Domain\Font\GraphFont;
use Kadupul\Graphing\Domain\Font\GraphFontMethod;
use Kadupul\Graphing\Domain\Font\GraphFontResolver;

function graph_font_theme(): array
{
    return array(
        'title' => array('font' => 'Arial', 'size' => '11'),
        'legend' => array('font' => 'Courier', 'size' => '8'),
        'axis' => array('font' => 'Arial'),
        'unit' => 'Arial',
    );
}

test('the viewer\'s fonts replace the site fonts as a whole', function () {
    $site = array('title' => array('font' => 'Site Sans', 'size' => '14'), 'legend' => array('font' => 'Site Mono', 'size' => '9'));
    $viewer = array('title' => array('font' => 'Viewer Serif', 'size' => '16'));

    $resolver = new GraphFontResolver();
    $mine = $resolver->resolve(array('title', 'legend'), GraphFontMethod::System, graph_font_theme(), $site, $viewer);
    $site_only = $resolver->resolve(array('title', 'legend'), GraphFontMethod::System, graph_font_theme(), $site, null);

    expect($mine->elements)->toEqual(array('title' => new GraphFont('Viewer Serif', 16.0), 'legend' => new GraphFont('', 8.0)))
        ->and($site_only->elements)->toEqual(array('title' => new GraphFont('Site Sans', 14.0), 'legend' => new GraphFont('Site Mono', 9.0)));
});

test('theme fonts leave out an element the theme does not fully define', function () {
    $profile = (new GraphFontResolver())->resolve(GraphFontResolver::ELEMENTS, GraphFontMethod::Theme, graph_font_theme(), array('title' => array('font' => 'Site Sans', 'size' => '30')), null);

    expect($profile->elements)->toEqual(array('title' => new GraphFont('Arial', 11.0), 'legend' => new GraphFont('Courier', 8.0)))
        ->and($profile->element('axis'))->toBeNull()
        ->and($profile->element('unit'))->toBeNull()
        ->and($profile->method)->toBe(GraphFontMethod::Theme);
});

test('sizes RRDtool cannot draw fall back to the element default and large ones are capped', function ($size, $title, $other) {
    expect(GraphFontResolver::size($size, 12.0))->toBe($title)
        ->and(GraphFontResolver::size($size, 8.0))->toBe($other);
})->with(array(
    'empty' => array('', 12.0, 8.0),
    'not a number' => array('abc', 12.0, 8.0),
    'null' => array(null, 12.0, 8.0),
    'an array' => array(array(9), 12.0, 8.0),
    'negative' => array('-8', 12.0, 8.0),
    'at the lower bound' => array('4', 12.0, 8.0),
    'just above the lower bound' => array('4.01', 4.01, 4.01),
    'leading blank' => array(' 10', 10.0, 10.0),
    'at the upper bound' => array('72', 72.0, 72.0),
    'just above the upper bound' => array('72.01', 72.0, 72.0),
    'huge' => array('1e9', 72.0, 72.0),
    'infinite' => array('1e400', 12.0, 8.0),
    'negative infinite' => array('-1e400', 12.0, 8.0),
    'hexadecimal' => array('0x10', 12.0, 8.0),
));

test('only sizes drawn as given are accepted on save', function ($size, $accepted) {
    expect(GraphFontResolver::acceptsSize($size))->toBe($accepted);
})->with(array(
    'empty' => array('', false),
    'not a number' => array('abc', false),
    'at the lower bound' => array('4', false),
    'just above the lower bound' => array('4.01', true),
    'an integer' => array(10, true),
    'at the upper bound' => array('72', true),
    'just above the upper bound' => array('72.01', false),
    'infinite' => array('1e400', false),
    'not a number, as a float' => array(NAN, false),
));

test('only the title defaults to 12 points', function () {
    $blank = array_fill_keys(GraphFontResolver::ELEMENTS, array('font' => '', 'size' => ''));
    $profile = (new GraphFontResolver())->resolve(GraphFontResolver::ELEMENTS, GraphFontMethod::System, array(), $blank, null);

    expect(array_map(fn(GraphFont $font) => $font->size, $profile->elements))
        ->toBe(array('title' => 12.0, 'axis' => 8.0, 'legend' => 8.0, 'unit' => 8.0, 'watermark' => 8.0));
});

test('a font value that is not text is no font', function ($font, $family) {
    $profile = (new GraphFontResolver())->resolve(array('axis'), GraphFontMethod::System, array(), array('axis' => array('font' => $font, 'size' => '8')), null);

    expect($profile->element('axis')->family)->toBe($family);
})->with(array(
    'false, as an unset user setting reads' => array(false, ''),
    'null' => array(null, ''),
    'an array' => array(array('Sans'), ''),
    'an integer' => array(0, '0'),
));

test('only characters fontconfig family names use are accepted', function ($description, $accepted) {
    expect(GraphFontResolver::acceptsFamily($description))->toBe($accepted);
})->with(array(
    'empty' => array('', true),
    'a family' => array('DejaVu Sans', true),
    'a family list, styles and a size' => array('Ubuntu, Cantarell Bold 11', true),
    'accents' => array("Caf\u{e9} Sans", true),
    'CJK' => array("\u{6e38}\u{30b4}\u{30b7}\u{30c3}\u{30af}", true),
    'a variation' => array('Inter @wght=600', true),
    'at the length limit' => array(str_repeat('A', 255), true),
    'over the length limit' => array(str_repeat('A', 256), false),
    'an apostrophe' => array("Mono 'x'", true),
    'a double quote' => array('Sans "Bold"', false),
    'a backslash' => array('Sans\\', false),
    'a colon' => array('Sans:bold', false),
    'a font file path' => array('/usr/share/fonts/DejaVuSans.ttf', false),
    'a tab' => array("Sans\tBold", false),
    'a trailing line break' => array("Sans\n", false),
    'not UTF-8' => array("Sans\xff", false),
));

test('a stored font name fontconfig cannot hold draws RRDtool\'s own font', function () {
    $profile = (new GraphFontResolver())->resolve(array('title', 'axis'), GraphFontMethod::System, array(), array(
        'title' => array('font' => "Sans\xff", 'size' => '10'),
        'axis' => array('font' => 'DejaVu Sans', 'size' => '8'),
    ), null, 'Sans "Bold"');

    expect($profile->element('title'))->toEqual(new GraphFont('', 10.0))
        ->and($profile->element('axis'))->toEqual(new GraphFont('DejaVu Sans', 8.0))
        ->and($profile->defaultFont)->toBe('');
});

test('the fingerprint changes with any drawn font and ignores element order', function () {
    $resolver = new GraphFontResolver();
    $site = array('title' => array('font' => 'Sans', 'size' => '10'), 'axis' => array('font' => 'Sans', 'size' => '8'));
    $base = $resolver->resolve(array('title', 'axis'), GraphFontMethod::System, array(), $site, null, 'DejaVu Sans');

    expect($resolver->resolve(array('axis', 'title'), GraphFontMethod::System, array(), $site, null, 'DejaVu Sans')->fingerprint())->toBe($base->fingerprint())
        // Stored differently, drawn the same.
        ->and($resolver->resolve(array('title', 'axis'), GraphFontMethod::System, array(), array('title' => array('font' => 'Sans', 'size' => '10.0')) + $site, null, 'DejaVu Sans')->fingerprint())->toBe($base->fingerprint())
        ->and($resolver->resolve(array('title', 'axis'), GraphFontMethod::System, array(), array('title' => array('font' => 'Serif', 'size' => '10')) + $site, null, 'DejaVu Sans')->fingerprint())->not->toBe($base->fingerprint())
        ->and($resolver->resolve(array('title', 'axis'), GraphFontMethod::System, array(), array('title' => array('font' => 'Sans', 'size' => '11')) + $site, null, 'DejaVu Sans')->fingerprint())->not->toBe($base->fingerprint())
        ->and($resolver->resolve(array('title', 'axis'), GraphFontMethod::System, array(), $site, null, 'Serif')->fingerprint())->not->toBe($base->fingerprint())
        ->and($resolver->resolve(array('title', 'axis'), GraphFontMethod::Theme, array(), $site, null, 'DejaVu Sans')->fingerprint())->not->toBe($base->fingerprint());
});
