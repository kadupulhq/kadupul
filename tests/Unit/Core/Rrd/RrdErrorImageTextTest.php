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
 * The RRDtool error image looked only for DejaVuSans.ttf, which
 * include/fonts does not ship, so hosts without system DejaVu fell back to
 * GD's bitmap fonts. That path passed the point size 8 as a GD font id,
 * which GD reads as its 9x15 Giant font, while the layout still assumed
 * 8 px: lines overlapped and ran past the frame. Messages were wrapped with
 * wordwrap(), which counts bytes and cut translated text inside a UTF-8
 * character.
 */

require_once dirname(__DIR__, 3) . '/Helpers/PhpSource.php';

$errorImageRoot = dirname(__DIR__, 4);

function errimg_get_selected_theme() {
	return 'modern';
}

function errimg_cacti_sizeof($array) {
	return is_array($array) ? count($array) : 0;
}

function errimg_cacti_log($message) {
	$GLOBALS['errimg_logs'][] = $message;
}

function errimg___($text) {
	return $text;
}

function errimg_is_resource_writable($path) {
	return true;
}

function errimg_load(string $root) : void {
	if (function_exists('errimg_rrdtool_create_error_image')) {
		return;
	}

	$source    = file_get_contents($root . '/lib/rrd.php');
	$functions = array('rrdtool_error_image_font', 'rrdtool_error_image_wrap', 'rrdtool_parse_error', 'rrdtool_create_error_image');
	$names     = 'rrdtool_error_image_font|rrdtool_error_image_wrap|rrdtool_parse_error|rrdtool_create_error_image|get_selected_theme|cacti_sizeof|cacti_log|__|is_resource_writable';

	foreach ($functions as $name) {
		/* test-only eval of the shipped function, read from this repository */
		eval(preg_replace('/(?<![\w$>])(' . $names . ')\(/', 'errimg_$1(', test_php_function_source($source, $name)));
	}
}

/**
 * @param array<int, string> $paths
 */
function errimg_configure(string $base, string $os, array $paths) : void {
	$GLOBALS['config']       = array('base_path' => $base, 'cacti_server_os' => $os);
	$GLOBALS['dejavu_paths'] = $paths;
	$GLOBALS['errimg_logs']  = array();
}

/**
 * Render an error image and return it with the PHP warnings raised.
 *
 * @return array{0: GdImage|resource, 1: array<int, string>}
 */
function errimg_render(string $message) : array {
	$warnings = array();

	set_error_handler(function ($errno, $errstr) use (&$warnings) {
		$warnings[] = $errstr;

		return true;
	});

	try {
		$png = errimg_rrdtool_create_error_image($message);
	} finally {
		restore_error_handler();
	}

	return array(imagecreatefromstring($png), $warnings);
}

/**
 * Columns and row bands that hold text pixels right of the logo, which ends
 * at x=109. The text is black; the darkest frame colour is 999999.
 *
 * @return array{min_x: int, max_x: int, top: int, bottom: int, starts: array<int, int>}
 */
function errimg_text_extent($image) : array {
	$extent = array('min_x' => PHP_INT_MAX, 'max_x' => -1, 'top' => -1, 'bottom' => -1, 'starts' => array());
	$in     = false;

	for ($y = 0; $y < imagesy($image); $y++) {
		$row = false;

		for ($x = 110; $x < imagesx($image); $x++) {
			$color = imagecolorsforindex($image, imagecolorat($image, $x, $y));

			if ($color['red'] < 100 && $color['green'] < 100 && $color['blue'] < 100) {
				$row             = true;
				$extent['min_x'] = min($extent['min_x'], $x);
				$extent['max_x'] = max($extent['max_x'], $x);
			}
		}

		if ($row) {
			if (!$in) {
				$extent['starts'][] = $y;
			}

			if ($extent['top'] < 0) {
				$extent['top'] = $y;
			}

			$extent['bottom'] = $y;
		}

		$in = $row;
	}

	return $extent;
}

function errimg_tempdir() : string {
	$dir = sys_get_temp_dir() . '/cacti-error-image-' . bin2hex(random_bytes(6));
	mkdir($dir, 0700);

	return $dir;
}

function errimg_rmdir(string $dir) : void {
	foreach (array_reverse(glob($dir . '/{,*/,*/*/}*', GLOB_BRACE)) as $path) {
		is_dir($path) ? rmdir($path) : unlink($path);
	}

	rmdir($dir);
}

beforeEach(function () use ($errorImageRoot) {
	errimg_load($errorImageRoot);
});

test('ASCII text wraps exactly as wordwrap() wraps it', function () {
	mt_srand(1231);

	$alphabet = array('a', 'b', 'c', ' ', ' ', "\n", 'x', 'y', 'z', '/');

	for ($i = 0; $i < 3000; $i++) {
		$text = '';

		for ($j = mt_rand(0, 90); $j > 0; $j--) {
			$text .= $alphabet[mt_rand(0, count($alphabet) - 1)];
		}

		$width = mt_rand(1, 40);

		expect(errimg_rrdtool_error_image_wrap($text, $width))->toBe(wordwrap($text, $width, "\n", true));
	}
});

test('translated text wraps on characters and never splits one', function (string $text) {
	$wrapped = errimg_rrdtool_error_image_wrap($text, 36);
	$lines   = explode("\n", $wrapped);

	/* a line that fits in 36 characters stays whole even when it is longer than 36 bytes */
	expect(count($lines) > 1)->toBe(mb_strlen($text, 'UTF-8') > 36)
		->and(mb_check_encoding($wrapped, 'UTF-8'))->toBeTrue()
		->and(str_replace(array("\n", ' '), '', $wrapped))->toBe(str_replace(' ', '', $text));

	foreach ($lines as $line) {
		expect(mb_strlen($line, 'UTF-8'))->toBeLessThanOrEqual(36);
	}

	/* wordwrap() counts bytes and cuts this text inside a character */
	expect(mb_check_encoding(wordwrap($text, 36, "\n", true), 'UTF-8'))->toBeFalse();
})->with(array(
	/* the shipped translations of the unwritable folder message */
	'ja-JP' => array('ウェブサイトにはfolderへの書き込みアクセス権がありません。RRDを作成/更新できない可能性があります'),
	'ru-RU' => array('Веб-сайт не имеет доступа на запись в folder, может не иметь возможности создавать/обновлять RRDs.'),
	'zh-CN' => array('网站没有 folder 的写入权限,可能无法创建/更新RRD'),
));

test('wrapping keeps a line of exactly the width and cuts one character past it', function () {
	$exact = str_repeat('ж', 36);

	expect(errimg_rrdtool_error_image_wrap($exact, 36))->toBe($exact)
		->and(errimg_rrdtool_error_image_wrap($exact . 'ж', 36))->toBe($exact . "\nж")
		->and(errimg_rrdtool_error_image_wrap('', 36))->toBe('');
});

test('text that is not valid UTF-8 falls back to wordwrap()', function () {
	$text = str_repeat("\xff\xfe", 30);

	expect(errimg_rrdtool_error_image_wrap($text, 36))->toBe(wordwrap($text, 36, "\n", true));
});

test('the error image prefers an installed regular DejaVu Sans', function () use ($errorImageRoot) {
	$dir = errimg_tempdir();

	try {
		copy($errorImageRoot . '/include/fonts/DejaVuSans-Bold.ttf', $dir . '/DejaVuSans.ttf');
		errimg_configure($errorImageRoot, 'unix', array($dir . '/missing', $dir, $errorImageRoot . '/include/fonts'));

		expect(errimg_rrdtool_error_image_font())->toBe($dir . '/DejaVuSans.ttf');
	} finally {
		errimg_rmdir($dir);
	}
});

test('the error image falls back to the bundled bold font', function (string $os) use ($errorImageRoot) {
	errimg_configure($errorImageRoot, $os, array('/nonexistent/fonts', $errorImageRoot . '/include/fonts'));

	expect(errimg_rrdtool_error_image_font())->toBe($errorImageRoot . '/include/fonts/DejaVuSans-Bold.ttf');
})->with(array('unix', 'win32'))->skip(is_file('C:/Windows/Fonts/Arial.ttf'), 'this host has Arial installed');

test('the error image reports no font when neither a system nor a bundled font exists', function () {
	$dir = errimg_tempdir();

	try {
		errimg_configure($dir, 'unix', array('/nonexistent/fonts', $dir . '/include/fonts'));

		expect(errimg_rrdtool_error_image_font())->toBeFalse();
	} finally {
		errimg_rmdir($dir);
	}
});

test('an error image drawn with the bundled font keeps translated text inside the frame', function () use ($errorImageRoot) {
	errimg_configure($errorImageRoot, 'unix', array('/nonexistent/fonts', $errorImageRoot . '/include/fonts'));

	list($image, $warnings) = errimg_render('Веб-сайт не имеет доступа на запись к папке, возможно, не удастся создать или обновить файлы RRD');
	$extent = errimg_text_extent($image);

	expect($warnings)->toBe(array())
		->and($GLOBALS['errimg_logs'])->toBe(array())
		->and(imagesx($image))->toBe(450)
		->and(imagesy($image))->toBe(200)
		->and(count($extent['starts']))->toBeGreaterThan(1)
		->and($extent['min_x'])->toBeGreaterThanOrEqual(125)
		->and($extent['max_x'])->toBeLessThan(447);
})->skip(!function_exists('imagettftext'), 'GD has no FreeType support');

test('the bitmap fallback separates its lines and stays inside the frame', function () use ($errorImageRoot) {
	$dir = errimg_tempdir();

	try {
		mkdir($dir . '/images');
		copy($errorImageRoot . '/images/cacti_error_image.png', $dir . '/images/cacti_error_image.png');
		errimg_configure($dir, 'unix', array('/nonexistent/fonts'));

		/* capitals only, so every line's ink starts on the same row of its glyph cell */
		$message = 'ERROR: THIS RRDTOOL FAILURE MESSAGE IS LONG ENOUGH TO NEED THREE OR FOUR LINES OF THE BITMAP FONT';

		list($image, $warnings) = errimg_render($message);
		$extent = errimg_text_extent($image);
		$lines  = count(explode("\n", wordwrap($message, (int) ceil(315 / imagefontwidth(5)), "\n", true)));
		$pitch  = imagefontheight(5) + 5;

		expect($warnings)->toBe(array())
			->and($lines)->toBeGreaterThan(2)
			->and($extent['starts'])->toHaveCount($lines)
			->and($extent['min_x'])->toBeGreaterThanOrEqual(125)
			->and($extent['max_x'])->toBeLessThan(447)
			->and(abs(($extent['top'] + $extent['bottom']) / 2 - 100))->toBeLessThanOrEqual(5);

		for ($i = 1; $i < count($extent['starts']); $i++) {
			expect($extent['starts'][$i] - $extent['starts'][$i - 1])->toBe($pitch);
		}
	} finally {
		errimg_rmdir($dir);
	}
});
