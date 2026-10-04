<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

require_once dirname(__DIR__, 3) . '/Helpers/RrdCharacterization.php';

/** Run $calls with no warnings allowed and return what each call returned. */
function rrd_error_image_run($test, array $calls, array $scenario = array()): array
{
    $scenario += array('options' => rrd_characterization_options(), 'calls' => $calls);

    return array_map(function ($result) {
        expect($result['diagnostics'])->toBe(array());

        return $result['returned'];
    }, rrd_characterization_run($test, $scenario)['results']);
}

function rrd_error_image_bundled_font(): string
{
    return dirname(__DIR__, 4) . '/include/fonts/DejaVuSans-Bold.ttf';
}

/** Every line starts at x=125 and stays inside the 2..447 by 2..197 canvas without touching the next one. */
function rrd_error_image_expect_inside(array $layout, bool $ttf): void
{
    $height = $layout['line_height'];
    $previous = null;
    foreach ($layout['lines'] as $line) {
        // imagettftext() places the baseline at y, imagestring() the top edge.
        $top = $ttf ? $line['y'] - $height : $line['y'];
        expect(mb_check_encoding($line['text'], 'UTF-8'))->toBeTrue()
            ->and($line['x'])->toBe(125)
            ->and($line['x'] + $line['width'])->toBeLessThanOrEqual(440)
            ->and($top)->toBeGreaterThanOrEqual(2)
            ->and($top + $height + ($ttf ? $height / 4 : 0))->toBeLessThanOrEqual(197);
        if ($previous !== null) {
            expect($top - $previous)->toBeGreaterThanOrEqual($height);
        }
        $previous = $top;
    }
}

test('the error image falls back to the bundled DejaVu font', function () {
    $calls = array(
        // No system DejaVu Sans, as on macOS or a minimal container.
        array('fn' => 'rrdtool_error_image_font', 'args' => array(), 'globals' => array('dejavu_paths' => array('/nonexistent/dejavu/', dirname(__DIR__, 4) . '/include/fonts')), 'catch' => true),
        // Windows without Arial.
        array('fn' => 'rrdtool_error_image_font', 'args' => array(), 'config' => array('cacti_server_os' => 'win32'), 'catch' => true),
    );

    expect(rrd_error_image_run($this, $calls))->toBe(array(rrd_error_image_bundled_font(), rrd_error_image_bundled_font()));
});

test('error text is wrapped by character and kept inside the frame', function () {
    $english = 'ERROR: opening \'/var/www/html/kadupul/rra/a_very_long_structured_path/router_traffic_in_12345.rrd\': No such file or directory';
    $japanese = 'ウェブサイトにはフォルダーへの書き込みアクセス権がありません。RRDを作成または更新できない可能性があります';
    $font = rrd_error_image_bundled_font();
    $calls = array();
    foreach (array($english, $japanese) as $text) {
        $calls[] = array('fn' => 'rrdtool_error_image_layout', 'args' => array($text, $font, 8), 'catch' => true);
        $calls[] = array('fn' => 'rrdtool_error_image_layout', 'args' => array($text, '', 8), 'catch' => true);
    }

    $layouts = rrd_error_image_run($this, $calls);

    foreach ($layouts as $index => $layout) {
        $ttf = $index % 2 === 0;
        expect($layout)->toBeArray()->toHaveKey('lines')
            ->and(count($layout['lines']))->toBeGreaterThan(1);
        rrd_error_image_expect_inside($layout, $ttf);
        if ($ttf) {
            expect($layout['font'])->toBe($font);
        } else {
            // A GD built-in font id, not the point size.
            expect($layout['font'])->toBeInt()->toBeGreaterThanOrEqual(1)->toBeLessThanOrEqual(5);
        }
    }
    // TrueType keeps every character; the built-in font draws ASCII only.
    expect(implode('', array_column($layouts[2]['lines'], 'text')))->toBe($japanese)
        ->and(implode('', array_column($layouts[3]['lines'], 'text')))->toBe(preg_replace('/[^\x20-\x7E]/u', '?', $japanese));
});

test('empty and oversized error text still fits', function () {
    $font = rrd_error_image_bundled_font();
    $long = trim(str_repeat('The RRDtool process ended before it wrote the graph. ', 40));
    $calls = array(
        array('fn' => 'rrdtool_error_image_layout', 'args' => array('   ', $font, 8), 'catch' => true),
        array('fn' => 'rrdtool_error_image_layout', 'args' => array($long, $font, 8), 'catch' => true),
        array('fn' => 'rrdtool_error_image_layout', 'args' => array($long, '', 8), 'catch' => true),
        // The largest size a theme can set.
        array('fn' => 'rrdtool_error_image_layout', 'args' => array($long, $font, 72), 'catch' => true),
    );

    $layouts = rrd_error_image_run($this, $calls);

    expect(array_column($layouts[0]['lines'], 'text'))->toBe(array('Unknown RRDtool Error'));
    foreach (array(1 => true, 2 => false, 3 => true) as $index => $ttf) {
        rrd_error_image_expect_inside($layouts[$index], $ttf);
        expect(end($layouts[$index]['lines'])['text'])->toEndWith('...');
    }
});

test('the error image has no TrueType font when none is installed', function () {
    $calls = array(array('fn' => 'rrdtool_error_image_font', 'args' => array(), 'globals' => array('dejavu_paths' => array('/nonexistent/dejavu/')), 'catch' => true));

    expect(rrd_error_image_run($this, $calls))->toBe(array(''));
});

test('blank lines keep their place and a bad size falls back', function () {
    $font = rrd_error_image_bundled_font();
    $text = "Could not open file:\n\n/rra/bad\u{00e9}name.rrd";
    $calls = array(
        array('fn' => 'rrdtool_error_image_layout', 'args' => array($text, $font, 8), 'catch' => true),
        array('fn' => 'rrdtool_error_image_layout', 'args' => array($text, '', 'abc'), 'catch' => true),
    );

    foreach (rrd_error_image_run($this, $calls) as $index => $layout) {
        rrd_error_image_expect_inside($layout, $index === 0);
        expect(array_column($layout['lines'], 'text'))->toBe(array('Could not open file:', '', $index === 0 ? "/rra/bad\u{00e9}name.rrd" : '/rra/bad?name.rrd'));
    }
});

test('the error image renders with only the bundled fonts', function () {
    $calls = array(array(
        'fn' => 'rrdtool_create_error_image',
        'args' => array('ERROR: ' . str_repeat('ファイルを開けません ', 6)),
        'globals' => array('dejavu_paths' => array(dirname(__DIR__, 4) . '/include/fonts')),
        'catch' => true,
    ));

    $image = rrd_error_image_run($this, $calls)[0];

    expect($image)->toBeString()->toContain('PNG')->toContain('IEND');
});

// The old GD path passed the point size (8) as a font id, which GD treats as its
// largest 9x15 font, and wrapped for a narrower one, so text ran over the frame.
test('rendered error text leaves the right margin and the frame untouched', function ($dejavu_paths) {
    $calls = array(array(
        'fn' => 'rrdtool_create_error_image',
        'args' => array('ERROR: ' . str_repeat('0123456789abcdef', 30)),
        'globals' => array('dejavu_paths' => $dejavu_paths),
        'base64' => true,
    ));

    $image = imagecreatefromstring(base64_decode(rrd_error_image_run($this, $calls)[0], true));

    expect($image)->not->toBeFalse();
    // Columns 441-447 are canvas and 448-449 the frame; each must be one colour between the borders.
    for ($x = 441; $x <= 449; $x++) {
        $colors = array();
        for ($y = 2; $y <= 197; $y++) {
            $colors[imagecolorat($image, $x, $y)] = true;
        }
        expect($colors)->toHaveCount(1, "column $x");
    }
    // Rows 0-1 and 198-199 are the frame above and below the text.
    foreach (array(0, 1, 198, 199) as $y) {
        $colors = array();
        for ($x = 125; $x <= 447; $x++) {
            $colors[imagecolorat($image, $x, $y)] = true;
        }
        expect($colors)->toHaveCount(1, "row $y");
    }
})->with(array(
    'built-in GD font' => array(array('/nonexistent/dejavu/')),
    'bundled TrueType font' => array(array(dirname(__DIR__, 4) . '/include/fonts')),
));
