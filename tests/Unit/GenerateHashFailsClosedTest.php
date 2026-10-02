<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

require_once dirname(__DIR__) . '/Helpers/PhpSource.php';

// Only the entropy failure is injected. Production bodies and native success
// are exercised separately; the original catch must see the same Exception.
eval(<<<'ADAPTER'
namespace HashEntropyFailure;
use Exception;
function random_bytes($length) { throw $GLOBALS['hash_entropy_failure']; }
ADAPTER);
$source = file_get_contents(dirname(__DIR__, 2) . '/lib/functions.php');
expect($source)->toBeString();
foreach (['generate_hash', 'get_hash_graph_template'] as $name) {
    eval('namespace HashEntropyFailure; use Exception; ' . test_php_function_source($source, $name));
}

test('native token generation and graph-template fallback preserve opaque identifiers', function () {
    $program = <<<'CHILD'
require $argv[1] . '/lib/functions.php';
$hashes = [];
for ($index = 0; $index < 1000; $index++) {
    $hash = generate_hash();
    if (!is_string($hash) || !preg_match('/\A[0-9a-f]{32}\z/D', $hash)) {
        throw new RuntimeException('Invalid native token shape');
    }
    $hashes[$hash] = true;
}
$caller = get_hash_graph_template(0, 'unrecognized-subtype');
echo json_encode([count($hashes), is_string($caller) && preg_match('/\A[0-9a-f]{32}\z/D', $caller) === 1], JSON_THROW_ON_ERROR);
CHILD;
    $process = proc_open([PHP_BINARY, '-d', 'error_reporting=E_ALL', '-r', $program, dirname(__DIR__, 2)], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    expect(is_resource($process))->toBeTrue();
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    expect(proc_close($process))->toBe(0)->and($stderr)->toBe('');
    expect(json_decode($stdout, true, flags: JSON_THROW_ON_ERROR))->toBe([1000, true]);
});

test('entropy failures propagate without returning a fallback token', function (string $caller) {
    $failure = new RuntimeException('Owned entropy failure');
    $GLOBALS['hash_entropy_failure'] = $failure;
    $returned = null;
    $actual = null;
    try {
        try {
            $returned = $caller === 'direct' ? HashEntropyFailure\generate_hash() : HashEntropyFailure\get_hash_graph_template(0, 'unrecognized-subtype');
        } catch (RuntimeException $error) {
            $actual = $error;
        }
        expect($actual)->toBe($failure)->and($returned)->toBeNull();
    } finally {
        unset($GLOBALS['hash_entropy_failure']);
    }
})->with(['direct', 'graph-template caller']);
