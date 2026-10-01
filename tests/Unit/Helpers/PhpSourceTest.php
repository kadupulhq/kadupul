<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

require_once dirname(__DIR__, 2) . '/Helpers/PhpSource.php';

test('block extraction ignores braces in strings and comments', function () {
    $source = <<<'PHP'
<?php
if ($first) {
    // } is not the end of the block.
    $text = "{still in a string}";
    if ($second) {
        echo $text;
    }
}
if ($later) { echo 'outside'; }
PHP;

    $block = test_php_block_source($source, 'if ($second)', 'if ($first)');

    expect($block)->toContain('if ($second)')
        ->and($block)->toContain('echo $text;')
        ->and($block)->not->toContain('outside');
});

test('block extraction balances interpolated expressions', function () {
    $source = <<<'PHP'
<?php
function selected() {
    $value = "{$items['key']}";
    return $value;
}
PHP;

    expect(test_php_block_source($source, 'function selected()'))->toContain("\$items['key']")
        ->and(test_php_block_source($source, 'function selected()'))->toEndWith('}');
});

test('subprocess helper returns stdout stderr and exit status', function () {
    $result = test_php_run('fwrite(STDERR, "warning"); echo "result"; exit(7);');

    expect($result)->toBe(array('out' => 'result', 'err' => 'warning', 'status' => 7));
});


test('subprocess helper drains stderr beyond the pipe capacity', function () {
    $result = test_php_run('fwrite(STDERR, str_repeat("x", 200000)); echo "ok";');
    expect($result)->toBe(array('out' => 'ok', 'err' => str_repeat('x', 200000), 'status' => 0));
});

test('subprocess helper accepts argv arrays and closes stdin', function () {
    $result = test_php_run(array(PHP_BINARY, '-r', 'echo $argv[1]; echo strlen(stream_get_contents(STDIN));', '--', 'argument'));
    expect($result)->toBe(array('out' => 'argument0', 'err' => '', 'status' => 0));
});

test('subprocess helper preserves file command errors and exit status', function () {
    $path = tempnam(sys_get_temp_dir(), 'php-source-command-');
    try {
        file_put_contents($path, '<?php fwrite(STDERR, "file warning"); echo "file result"; exit(9);');
        expect(test_php_run(array(PHP_BINARY, $path)))->toBe(array('out' => 'file result', 'err' => 'file warning', 'status' => 9));
    } finally {
        unlink($path);
    }
});

test('block extraction reports malformed and missing boundaries', function ($source, $needle, $after, $error) {
    expect(fn() => test_php_block_source($source, $needle, $after))->toThrow(RuntimeException::class, $error);
})->with(array(
    array('<?php if ($ok) {}', 'if', 'missing', 'Block anchor not found: missing'),
    array('<?php if ($ok) {}', 'missing', '', 'Block marker not found: missing'),
    array('<?php marker', 'marker', '', 'Block opening brace not found: marker'),
    array('<?php marker { echo "x";', 'marker', '', 'Block has no complete body: marker'),
    array('marker { echo "x"; }', 'marker', '', 'Block opening brace not found: marker'),
));

test('block markers use the first literal source occurrence', function () {
    $source = '<?php // selected {comment}' . "\n" . 'if ($ok) { echo "body"; }';
    expect(test_php_block_source($source, 'selected'))->toBe('selected {comment}' . "\n" . 'if ($ok) { echo "body"; }');
});
