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
