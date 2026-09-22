<?php

/* Copyright (C) 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-2.0-or-later */

namespace CallContractTest;

require_once __DIR__ . '/../../../Helpers/PhpSource.php';

$baseline = json_decode(file_get_contents(__DIR__ . '/call-contract-baseline.json'), true, 512, JSON_THROW_ON_ERROR);
$cases = array();
foreach ($baseline as $entry) { $cases[$entry['key']] = array($entry); }
dataset('call contracts', $cases);

test('all sixteen call-signature findings have distinct traceability', function () use ($baseline) {
	expect(count(array_unique(array_column($baseline, 'key'))))->toBe(16);
});

test('production callers match declared signatures and surplus removal preserves consumed arguments', function ($entry) use ($baseline) {
	$root = dirname(__DIR__, 4);
	$source = file_get_contents($root . '/' . $entry['file']);
	$expected = array_filter($baseline, function ($other) use ($entry) {
		return $other['file'] === $entry['file'] && $other['after'] === $entry['after'];
	});
	expect(substr_count("\n" . $source, "\n" . $entry['after'] . "\n"))->toBe(count($expected));
	$definition = test_php_function_source(file_get_contents($root . '/' . $entry['definitionFile']), $entry['function']);
	$parser = (new \PhpParser\ParserFactory())->createForNewestSupportedVersion();
	$function = $parser->parse('<?php ' . $definition)[0];
	$old = $parser->parse('<?php ' . $entry['oldCall'] . ';')[0]->expr;
	$new = $parser->parse('<?php ' . $entry['newCall'] . ';')[0]->expr;
	expect(count($function->params))->toBe($entry['arity']);
	expect(count($new->args))->toBe($entry['arity']);
	if ($entry['kind'] === 'remove unused surplus') {
		expect($definition)->not->toMatch('/\b(?:func_get_args|func_get_arg|func_num_args|debug_backtrace)\s*\(/');
		$printer = new \PhpParser\PrettyPrinter\Standard();
		foreach ($new->args as $index => $arg) {
			expect($printer->prettyPrintExpr($arg->value))->toBe($printer->prettyPrintExpr($old->args[$index]->value));
		}
		foreach (array_slice($old->args, count($new->args)) as $arg) {
			// Removed arguments are only literals or variable reads, not calls with side effects.
			expect($arg->value instanceof \PhpParser\Node\Scalar
				|| $arg->value instanceof \PhpParser\Node\Expr\ConstFetch
				|| $arg->value instanceof \PhpParser\Node\Expr\Variable)->toBeTrue();
		}
	}
})->with('call contracts');
