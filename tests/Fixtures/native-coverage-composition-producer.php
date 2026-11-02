<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

use Kadupul\Tests\NativeCoverageComposition;
use Kadupul\Tests\NativeCoverageStack;
use SebastianBergmann\CodeCoverage\CodeCoverage;
use SebastianBergmann\CodeCoverage\Driver\Selector;
use SebastianBergmann\CodeCoverage\Filter;

$root = dirname(__DIR__, 2);
require $root . '/tests/Symfony/NativeCoverageStack.php';
if (!in_array($argc, [4, 5], true) || !in_array($argv[1], ['application', 'tests'], true)
    || $argc === 5 && !in_array($argv[4], ['overlap', 'reused-pid'], true)) throw new RuntimeException('Invalid composition fixture arguments.');
NativeCoverageStack::bootstrap($root, NativeCoverageStack::installed($root, $argv[1]));
require $root . '/tests/Symfony/NativeCoverageComposition.php';
$filter = new Filter();
$filter->includeFile(__DIR__ . '/native-coverage-composition-target.php');
// An unexecuted filter member must survive composition, too.
$filter->includeFile(__DIR__ . '/native-coverage-stack-target.php');
$filter->includeFile(__DIR__ . '/native-coverage-composition-application.php');
$coverage = new CodeCoverage((new Selector())->forLineCoverage($filter), $filter);
$reusedPid = ($argv[4] ?? '') === 'reused-pid';
if ($reusedPid) {
    require_once $root . '/tests/Helpers/PhpSource.php';
    eval(test_php_function_source(file_get_contents($root . '/tests/Fixtures/rrd-process-coverage.php'), 'rrd_test_coverage_invocation'));
}
$invocations = [];
$record = static function (CodeCoverage $collected, array $invocation) use ($root, &$invocations): void {
    $serialized = serialize($collected);
    if (file_put_contents($invocation['report'], $serialized) !== strlen($serialized)) throw new RuntimeException('Cannot publish invocation report.');
    $sources = ['tests/Fixtures/rrd-process-coverage.php', 'tests/Helpers/PhpSource.php', 'tests/Helpers/NativeChildCoverageEvidence.php',
        'tests/Symfony/NativeCoverageStack.php', 'tests/Fixtures/native-coverage-composition-target.php',
        'tests/Fixtures/native-coverage-stack-target.php', 'tests/Fixtures/native-coverage-composition-application.php'];
    $producer = 'tests/Fixtures/native-coverage-composition-producer.php';
    $stack = NativeCoverageStack::capture($root);
    $snapshot = NativeChildCoverageEvidence::snapshot($root, $producer, 'reused-pid', $sources, $stack);
    NativeChildCoverageEvidence::write($invocation['report'], $root, $snapshot, ['reused-pid-completed']);
    NativeChildCoverageEvidence::load($invocation['report'], $root, $producer, 'reused-pid', $sources,
        ['reused-pid-completed'], ['tests/Fixtures/native-coverage-composition-target.php'], $stack);
    $invocations[] = ['report' => $invocation['report'], 'sha256' => hash_file('sha256', $invocation['report']),
        'sidecar_sha256' => hash_file('sha256', $invocation['report'] . '.json')];
};
if ($reusedPid) {
    require_once $root . '/tests/Helpers/NativeChildCoverageEvidence.php';
    $invocation = rrd_test_coverage_invocation(dirname($argv[3]), 42);
}
$coverage->start($reusedPid ? $invocation['id'] : 'native composition ' . $argv[1]);
require __DIR__ . '/native-coverage-composition-target.php';
if (native_composition_target($argv[1] === 'tests' ? 1 : 2) !== ($argv[1] === 'tests' ? 11 : 22)) throw new RuntimeException('The native fixture was not executed.');
if ($argv[1] === 'application') {
    require __DIR__ . '/native-coverage-composition-application.php';
    if (native_composition_application_only(1) !== 11 || native_composition_application_only(2) !== 22) throw new RuntimeException('The application-only fixture was not executed.');
}
$coverage->stop();
if (($argv[4] ?? '') === 'overlap') {
    $value = $argv[1] === 'tests' ? 1 : 2;
    $coverage->start('native overlap B ' . $argv[1]);
    native_composition_target($value);
    $coverage->stop();
    $overlap = new CodeCoverage((new Selector())->forLineCoverage($filter), $filter);
    $overlap->start('native composition ' . $argv[1]);
    native_composition_target($value);
    $overlap->stop();
    $overlap->start('native overlap C ' . $argv[1]);
    native_composition_target($value);
    $overlap->stop();
    $coverage->merge($overlap);
}
if ($reusedPid) {
    $second = new CodeCoverage((new Selector())->forLineCoverage($filter), $filter);
    $record($coverage, $invocation);
    $secondInvocation = rrd_test_coverage_invocation(dirname($argv[3]), 42);
    $second->start($secondInvocation['id']);
    native_composition_target($argv[1] === 'tests' ? 2 : 1);
    $second->stop();
    $record($second, $secondInvocation);
    $coverage->merge($second);
    file_put_contents($argv[3] . '.invocations.json', json_encode($invocations, JSON_THROW_ON_ERROR));
}
NativeCoverageComposition::write($coverage, $root, $argv[2], $argv[3]);
(new \SebastianBergmann\CodeCoverage\Report\Clover())->process($coverage, $argv[3] . '.xml');
