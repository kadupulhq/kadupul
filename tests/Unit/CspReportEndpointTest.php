<?php
/*
 * SPDX-FileCopyrightText: 2004-2026 The Cacti Group
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

/* Prevent the entry-point block from executing when the file is included
 * during testing. The guard in csp_report_endpoint.php checks for this
 * constant and returns before touching $_SERVER or php://input. */
define('CACTI_CSP_REPORT_TEST_MODE', 1);

/* functions.php is required by the endpoint; stub cacti_log() so the test
 * process does not need a live database or session. */
if (!function_exists('cacti_log')) {
	function cacti_log($message, $stdout = false, $facility = 'CACTI') {
		/* intentional no-op in test context */
	}
}

require_once __DIR__ . '/../../lib/csp_report_endpoint.php';

/* ---- helpers ---- */

function _headers($ct) {
	return array('CONTENT_TYPE' => $ct);
}

/* ---- tests ---- */

test('validator rejects oversize body', function () {
	$ct   = 'application/csp-report';
	$body = str_repeat('a', 20000);
	$result = csp_report_validate_payload(_headers($ct), $body, 16384);
	expect($result['ok'])->toBeFalse();
	expect(strtolower($result['reason']))->toContain('size');
});

test('validator rejects wrong content type', function () {
	$result = csp_report_validate_payload(
		_headers('text/plain'),
		'{"csp-report":{}}',
		16384
	);
	expect($result['ok'])->toBeFalse();
	expect(strtolower($result['reason']))->toContain('content-type');
});

test('validator rejects malformed JSON', function () {
	$result = csp_report_validate_payload(
		_headers('application/csp-report'),
		'not json{',
		16384
	);
	expect($result['ok'])->toBeFalse();
	expect(strtolower($result['reason']))->toContain('json');
});

test('validator accepts legacy csp-report format', function () {
	$body = json_encode(array(
		'csp-report' => array(
			'violated-directive' => 'script-src',
			'blocked-uri'        => 'inline',
			'document-uri'       => 'https://x/y',
		),
	));
	$result = csp_report_validate_payload(_headers('application/csp-report'), $body, 16384);
	expect($result['ok'])->toBeTrue();
	expect($result['summary'])->toContain('script-src');
	expect($result['summary'])->toContain('inline');
	expect($result['summary'])->toContain('https://x/y');
});

test('validator accepts Reporting API body format', function () {
	$body = json_encode(array(
		'type' => 'csp-violation',
		'body' => array(
			'effectiveDirective' => 'script-src',
			'blockedURL'         => 'https://evil.example/x.js',
			'documentURL'        => 'https://app.example/page',
		),
	));
	$result = csp_report_validate_payload(_headers('application/json'), $body, 16384);
	expect($result['ok'])->toBeTrue();
	expect($result['summary'])->toContain('script-src');
});

/* ---- rate-limit buckets ---- */

function _csp_bucket_base() {
	$base = sys_get_temp_dir() . '/kadupul-csp-test-' . bin2hex(random_bytes(4));
	mkdir($base, 0700);

	return $base;
}

test('rate limiter keeps buckets in a private directory and caps each client', function () {
	$base = _csp_bucket_base();
	$_SERVER['REMOTE_ADDR'] = '192.0.2.10';

	for ($i = 0; $i < 30; $i++) {
		expect(csp_report_should_log($base))->toBeTrue();
	}

	expect(csp_report_should_log($base))->toBeFalse();
	expect(fileperms($base . '/kadupul_csp') & 0777)->toBe(0700);
});

test('rate limiter refuses a bucket directory other users can write', function () {
	$base = _csp_bucket_base();
	mkdir($base . '/kadupul_csp', 0700);
	chmod($base . '/kadupul_csp', 0777);
	$_SERVER['REMOTE_ADDR'] = '192.0.2.11';

	for ($i = 0; $i < 35; $i++) {
		expect(csp_report_should_log($base))->toBeTrue();
	}

	expect(glob($base . '/kadupul_csp/*'))->toBe([]);
});

test('rate limiter refuses a symlinked bucket directory', function () {
	$base   = _csp_bucket_base();
	$target = _csp_bucket_base();
	symlink($target, $base . '/kadupul_csp');
	$_SERVER['REMOTE_ADDR'] = '192.0.2.12';

	expect(csp_report_should_log($base))->toBeTrue();
	expect(glob($target . '/*'))->toBe([]);
});
