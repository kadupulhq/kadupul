<?php
// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-2.0-or-later
require_once dirname(__DIR__, 3) . '/Helpers/PhpSource.php';

test('function extraction handles comments strings interpolation and nested closures', function () {
    $body = <<<'CODE'
function &sample($value) {
    // } function decoy() {
    $literal = '{ }';
    $nested = function () { return "{$value}"; };
    $text = <<<TEXT
{ literal } ${value}
TEXT;
    return $value;
}
CODE;
    expect(test_php_function_source("<?php\n" . $body . "\nfunction next_one() {}", 'sample'))->toBe($body);
});

test('function extraction rejects missing and incomplete bodies', function ($source, $name) {
    expect(fn () => test_php_function_source($source, $name))->toThrow(RuntimeException::class);
})->with(array(array('<?php function other() {}', 'absent'), array('<?php function broken() {', 'broken'), array('<?php abstract class X { abstract function noBody(); }', 'noBody')));
