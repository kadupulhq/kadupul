<?php
declare(strict_types=1);
// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-2.0-or-later
namespace ResourceCacheFailure;
require_once dirname(__DIR__, 3) . '/Helpers/PhpSource.php';
$source = file_get_contents(dirname(__DIR__, 4) . '/lib/poller.php');
foreach (['cache_in_path', 'update_db_from_path', 'md5sum_path', 'should_ignore_from_replication', 'resource_cache_out', 'resource_cache_replace'] as $name) eval('namespace ' . __NAMESPACE__ . ';' . \test_php_function_source($source, $name));
if (!defined('POLLER_VERBOSITY_MEDIUM')) define('POLLER_VERBOSITY_MEDIUM', 3);
function read_config_option($name) { return $GLOBALS['resource_review_settings'][$name] ?? ''; }
function set_config_option($name, $value) { $GLOBALS['resource_review_settings'][$name] = $value; }
function db_fetch_cell_prepared($sql, $params = [], $column = '', $log = true, $conn = false) { return str_contains($sql, 'SELECT contents') ? ($GLOBALS['resource_encoded'] ?? false) : false; }
function sql_save($save, $table) { $GLOBALS['resource_review_attempts']++; return $GLOBALS['resource_save_success'] ?? false; }
function cacti_log($message, ...$args) {}
function cacti_sizeof($v) { return is_countable($v) ? count($v) : 0; }
function db_fetch_assoc_prepared($sql, $params = [], $log = true, $conn = false) { return [['id' => 1, 'path' => 'scripts/sample.txt', 'md5sum' => ($GLOBALS['resource_digest'] ?? md5('replacement')), 'attributes' => 33188]]; }
test('a failed resource-cache save is retried without requiring another source edit', function () {
    $dir = sys_get_temp_dir() . '/resource-producer-review-' . bin2hex(random_bytes(6));
    mkdir($dir); file_put_contents($dir . '/sample.txt', 'new content');
    $GLOBALS['config'] = ['base_path' => $dir]; $GLOBALS['resource_review_settings'] = []; $GLOBALS['resource_review_attempts'] = 0;
    try {
        cache_in_path($dir, 'scripts'); cache_in_path($dir, 'scripts');
        expect($GLOBALS['resource_review_attempts'])->toBe(2);
    } finally { unlink($dir . '/sample.txt'); rmdir($dir); }
});
test('failed resource content read preserves the collector file', function () {
    $dir = sys_get_temp_dir() . '/resource-consumer-review-' . bin2hex(random_bytes(6));
    mkdir($dir); mkdir($dir . '/scripts'); file_put_contents($dir . '/scripts/sample.txt', 'previous');
    $GLOBALS['config'] = ['base_path' => $dir]; $GLOBALS['resource_review_settings'] = []; $GLOBALS['remote_db_cnn_id'] = false;
    try {
        resource_cache_out('scripts', ['path' => $dir . '/scripts', 'recursive' => false]);
        $content = file_get_contents($dir . '/scripts/sample.txt');
        expect($content)->toBe('previous');
    } finally { unlink($dir . '/scripts/sample.txt'); rmdir($dir . '/scripts'); rmdir($dir); }
});

function file_put_contents($path, $contents, $flags = 0) {
    if (($GLOBALS['resource_failure'] ?? '') === 'short_write' && str_contains($path, '.cacti-resource-')) {
        return \file_put_contents($path, substr($contents, 0, 1), $flags);
    }
    return \file_put_contents($path, $contents, $flags);
}
function rename($from, $to) {
    return ($GLOBALS['resource_failure'] ?? '') === 'rename' ? false : \rename($from, $to);
}
function chmod($path, $mode) {
    return ($GLOBALS['resource_failure'] ?? '') === 'chmod' ? false : \chmod($path, $mode);
}

beforeEach(function () {
    $GLOBALS['resource_failure'] = '';
    $GLOBALS['resource_save_success'] = false;
    $GLOBALS['resource_encoded'] = false;
    $GLOBALS['resource_digest'] = md5('replacement');
});

test('verified replacement preserves the old file on every write failure', function ($failure) {
    $dir = sys_get_temp_dir() . '/resource-atomic-' . bin2hex(random_bytes(6));
    mkdir($dir); $path = $dir . '/sample.txt';
    file_put_contents($path, 'previous');
    $GLOBALS['resource_failure'] = $failure;
    try {
        $ok = resource_cache_replace($path, 'replacement', 0640);
        expect($ok)->toBe($failure === '');
        expect(file_get_contents($path))->toBe($failure === '' ? 'replacement' : 'previous');
        expect(glob($dir . '/.cacti-resource-*'))->toBe([]);
        if ($ok) expect(fileperms($path) & 0777)->toBe(0640);
    } finally { unlink($path); rmdir($dir); }
})->with(['', 'short_write', 'rename', 'chmod']);

test('resource contents are decoded and verified before replacement including valid empty files', function ($encoded, $digest, $expected) {
    $dir = sys_get_temp_dir() . '/resource-payload-' . bin2hex(random_bytes(6));
    mkdir($dir); mkdir($dir . '/scripts'); $path = $dir . '/scripts/sample.txt';
    file_put_contents($path, 'previous');
    $GLOBALS['config'] = ['base_path' => $dir]; $GLOBALS['resource_review_settings'] = [];
    $GLOBALS['remote_db_cnn_id'] = false; $GLOBALS['resource_encoded'] = $encoded; $GLOBALS['resource_digest'] = $digest;
    try {
        resource_cache_out('scripts', ['path' => $dir . '/scripts', 'recursive' => false]);
        expect(file_get_contents($path))->toBe($expected);
    } finally { unlink($path); rmdir($dir . '/scripts'); rmdir($dir); }
})->with([
    [false, md5('replacement'), 'previous'],
    ['@@@', md5('replacement'), 'previous'],
    [base64_encode('wrong'), md5('replacement'), 'previous'],
    [base64_encode('replacement'), md5('replacement'), 'replacement'],
    ['', md5(''), ''],
]);

test('a successful retry advances the directory digest and an unchanged directory does no further saves', function () {
    $dir = sys_get_temp_dir() . '/resource-retry-' . bin2hex(random_bytes(6));
    mkdir($dir); mkdir($dir . '/nested'); file_put_contents($dir . '/nested/file.txt', 'contents');
    $GLOBALS['config'] = ['base_path' => $dir]; $GLOBALS['resource_review_settings'] = []; $GLOBALS['resource_review_attempts'] = 0;
    try {
        expect(cache_in_path($dir, 'scripts'))->toBeFalse();
        expect($GLOBALS['resource_review_settings'])->toBe([]);
        $GLOBALS['resource_save_success'] = 1;
        expect(cache_in_path($dir, 'scripts'))->toBeTrue();
        expect(cache_in_path($dir, 'scripts'))->toBeTrue();
        expect($GLOBALS['resource_review_attempts'])->toBe(2);
    } finally { unlink($dir . '/nested/file.txt'); rmdir($dir . '/nested'); rmdir($dir); }
});
