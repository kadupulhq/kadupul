<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDtool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
*/

/*
 * Behavior tests for cacti_validate_theme().
 *
 * Root-cause mitigation for GHSA-rm7p / GHSA-cx5r (LFI via graph_theme).
 * The helper must allowlist-validate the theme name against the actual
 * contents of include/themes/ and return a safe default for anything else.
 *
 * Tests execute the production helper against the shipped theme directories.
 */

beforeAll(function () {
	require_once dirname(__DIR__, 4) . '/include/global_constants.php';
	require_once dirname(__DIR__, 4) . '/lib/functions.php';
});

beforeEach(function () {
    $GLOBALS['config'] = array('base_path' => dirname(__DIR__, 4), 'is_web' => false,
        'config_options_array' => array('selected_theme' => 'modern'));
});

// cacti_validate_theme source contract
$src = file_get_contents(__DIR__ . '/../../../../lib/functions.php');

it('cacti_validate_theme source contract — uses static cache so scandir runs once per request', function () use ($src) {
	expect($src)->toContain('static $valid_themes');
});

it('cacti_validate_theme source contract — requires both is_dir and is_file(rrdtheme.php) for allowlist entry', function () use ($src) {
	expect($src)->toContain('is_dir($full)');
	expect($src)->toContain("is_file(\$full . '/rrdtheme.php')");
});

it('cacti_validate_theme source contract — applies basename() to requested value before allowlist check', function () use ($src) {
	expect($src)->toContain('basename((string) $requested)');
});

it('cacti_validate_theme source contract — falls back to a configured or modern default', function () use ($src) {
	expect($src)->toContain("read_config_option('selected_theme')");
	expect($src)->toContain("\$default = 'modern'");
});

// theme allowlist algorithm

it('theme allowlist algorithm — accepts a valid theme', function () {
	expect(cacti_validate_theme('modern'))->toBe('modern');
	expect(cacti_validate_theme('midwinter'))->toBe('midwinter');
});

it('theme allowlist algorithm — returns default for an invalid theme', function () {
	expect(cacti_validate_theme('evil'))->toBe('modern');
});

it('theme allowlist algorithm — strips path traversal via basename', function () {
	// basename('../../etc/passwd') => 'passwd'; not in allowlist; returns default
	expect(cacti_validate_theme('../../etc/passwd'))->toBe('modern');
	expect(cacti_validate_theme('/etc/passwd'))->toBe('modern');
	expect(cacti_validate_theme('modern/../../etc/passwd'))->toBe('modern');
});

it('theme allowlist algorithm — rejects empty, dot, and double-dot', function () {
	expect(cacti_validate_theme(''))->toBe('modern');
	expect(cacti_validate_theme('.'))->toBe('modern');
	expect(cacti_validate_theme('..'))->toBe('modern');
});

it('theme allowlist algorithm — rejects theme names satisfying basename but not in allowlist', function () {
	// The exploit category the plain-basename fix missed:
	// attacker-placed directory with rrdtheme.php. Our allowlist is
	// built from the real include/themes/ so this is blocked unless
	// the attacker can write INTO include/themes/ (already game over).
	expect(cacti_validate_theme('attacker_uploaded_theme'))->toBe('modern');
});

it('theme allowlist algorithm — is case-sensitive (filesystem names are case-sensitive on POSIX)', function () {
	expect(cacti_validate_theme('MODERN'))->toBe('modern');
	expect(cacti_validate_theme('Modern'))->toBe('modern');
});

it('theme allowlist algorithm — coerces non-string input safely', function () {
	expect(cacti_validate_theme(null))->toBe('modern');
	expect(cacti_validate_theme(0))->toBe('modern');
	expect(cacti_validate_theme(false))->toBe('modern');
});

// theme ingress enforcement
$graphImageSource = file_get_contents(__DIR__ . '/../../../../graph_image.php');
$graphJsonSource  = file_get_contents(__DIR__ . '/../../../../graph_json.php');
$remoteSource     = file_get_contents(__DIR__ . '/../../../../remote_agent.php');

it('theme ingress enforcement — uses cacti_validate_theme in graph_image request handling', function () use ($graphImageSource) {
	expect($graphImageSource)->toContain("cacti_validate_theme(get_request_var('graph_theme'))");
});

it('theme ingress enforcement — uses cacti_validate_theme in graph_json request handling', function () use ($graphJsonSource) {
	expect($graphJsonSource)->toContain("cacti_validate_theme(get_request_var('graph_theme'))");
});

it('theme ingress enforcement — uses cacti_validate_theme in remote_agent graph handler', function () use ($remoteSource) {
	expect($remoteSource)->toContain("cacti_validate_theme(get_request_var('graph_theme'))");
});
