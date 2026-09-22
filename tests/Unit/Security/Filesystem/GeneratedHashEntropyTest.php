<?php
// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-2.0-or-later

namespace GeneratedHashEntropyTest;

require_once dirname(__DIR__, 3) . '/Helpers/PhpSource.php';

function random_bytes($length) {
	if ($length !== 16) throw new \LogicException('Identifier must contain 128 bits');
	$mode = $GLOBALS['generated_hash_entropy_mode'];
	if ($mode === 'failure') throw new \RuntimeException('Entropy unavailable');
	return $mode === 'real' ? \random_bytes($length) : str_repeat(chr($mode), $length);
}

$source = file_get_contents(dirname(__DIR__, 4) . '/lib/functions.php');
eval('namespace ' . __NAMESPACE__ . ';' . \test_php_function_source($source, 'generate_hash'));

test('generated identifiers preserve the lowercase 32-character hex contract', function ($byte) {
	$GLOBALS['generated_hash_entropy_mode'] = $byte;
	$hash = generate_hash();
	expect($hash)->toBe(bin2hex(str_repeat(chr($byte), 16)));
	expect($hash)->toMatch('/^[0-9a-f]{32}$/');
})->with([0, 1, 171, 255]);

test('entropy failure cannot produce a fallback identifier and later requests can recover', function () {
	$GLOBALS['generated_hash_entropy_mode'] = 'failure';
	expect(fn () => generate_hash())->toThrow(\RuntimeException::class, 'Entropy unavailable');
	$GLOBALS['generated_hash_entropy_mode'] = 171;
	expect(generate_hash())->toBe(str_repeat('ab', 16));
});

test('normal identifiers use real secure entropy without accidental caching', function () {
	$GLOBALS['generated_hash_entropy_mode'] = 'real';
	$hashes = [];
	for ($i = 0; $i < 128; $i++) {
		$hash = generate_hash();
		expect($hash)->toMatch('/^[0-9a-f]{32}$/');
		$hashes[] = $hash;
	}
	expect(count(array_unique($hashes)))->toBe(128);
});

test('real-time session initialization never stores a predictable fallback', function ($mode) {
	$previous = $_SESSION ?? null;
	try {
		$_SESSION = $mode === 'existing' ? ['sess_realtime_hash' => str_repeat('cd', 16)] : [];
		$GLOBALS['generated_hash_entropy_mode'] = $mode === 'healthy' ? 171 : 'failure';
		$source = file_get_contents(dirname(__DIR__, 4) . '/graph_realtime.php');
		$start = strpos($source, "if (!isset(\$_SESSION['sess_realtime_hash'])) {");
		$end = strpos($source, "\n\$hash =", $start);
		expect($start)->not->toBeFalse();
		expect($end)->not->toBeFalse();
		$initialize = function () use ($source, $start, $end) {
			eval('namespace ' . __NAMESPACE__ . ';' . substr($source, $start, $end - $start));
		};
		if ($mode === 'failure') {
			expect($initialize)->toThrow(\RuntimeException::class, 'Entropy unavailable');
			expect(array_key_exists('sess_realtime_hash', $_SESSION))->toBeFalse();
		} else {
			$initialize();
			expect($_SESSION['sess_realtime_hash'])->toBe(str_repeat($mode === 'existing' ? 'cd' : 'ab', 16));
		}
	} finally {
		if ($previous === null) unset($_SESSION);
		else $_SESSION = $previous;
	}
})->with(['healthy', 'failure', 'existing']);
