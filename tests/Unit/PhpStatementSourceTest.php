<?php
declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-2.0-or-later

require_once dirname(__DIR__) . '/Helpers/PhpStatementSource.php';

function assignment_source_fixture_value(string $value): string { return 'processed:' . $value; }

test('assignment extraction executes the selected variable rather than adjacent calls', function (): void {
	$source = '<?php $other = assignment_source_fixture_value("other"); $wanted /* comment */ = assignment_source_fixture_value("value;with;semicolons");';
	eval(test_php_assignment_source($source, '$wanted', 'assignment_source_fixture_value'));
	expect($wanted)->toBe('processed:value;with;semicolons');
	expect(isset($other))->toBeFalse();
});

test('assignment extraction preserves nested closure statements and quoted array keys', function (string $key): void {
	$source = '<?php $record[' . $key . '] = assignment_source_fixture_value((function () { $inner = "value;"; return $inner; })());';
	$record = array();
	eval(test_php_assignment_source($source, '$record', 'assignment_source_fixture_value', 'HTTP_REFERER'));
	expect($record)->toBe(array('HTTP_REFERER' => 'processed:value;'));
})->with(array("'HTTP_REFERER'", '"HTTP_\\x52EFERER"'));

test('assignment extraction accepts an explicitly global call', function (): void {
	eval(test_php_assignment_source('<?php $wanted = \\assignment_source_fixture_value("global");', '$wanted', 'assignment_source_fixture_value'));
	expect($wanted)->toBe('processed:global');
});

test('assignment extraction rejects missing ambiguous and incomplete producers', function (string $source): void {
	expect(fn() => test_php_assignment_source($source, '$wanted', 'assignment_source_fixture_value'))->toThrow(RuntimeException::class);
})->with(array(
	'absent' => '<?php $other = assignment_source_fixture_value("other");',
	'ambiguous' => '<?php $wanted = assignment_source_fixture_value("a"); $wanted = assignment_source_fixture_value("b");',
	'incomplete' => '<?php $wanted = assignment_source_fixture_value("a")',
));
