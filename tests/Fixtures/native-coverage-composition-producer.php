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
if ($argc !== 4 || !in_array($argv[1], ['application', 'tests'], true)) throw new RuntimeException('Invalid composition fixture arguments.');
NativeCoverageStack::bootstrap($root, NativeCoverageStack::installed($root, $argv[1]));
require $root . '/tests/Symfony/NativeCoverageComposition.php';
$filter = new Filter();
$filter->includeFile(__DIR__ . '/native-coverage-composition-target.php');
// An unexecuted filter member must survive composition, too.
$filter->includeFile(__DIR__ . '/native-coverage-stack-target.php');
$filter->includeFile(__DIR__ . '/native-coverage-composition-application.php');
$coverage = new CodeCoverage((new Selector())->forLineCoverage($filter), $filter);
$coverage->start('native composition ' . $argv[1]);
require __DIR__ . '/native-coverage-composition-target.php';
if (native_composition_target($argv[1] === 'tests' ? 1 : 2) !== ($argv[1] === 'tests' ? 11 : 22)) throw new RuntimeException('The native fixture was not executed.');
if ($argv[1] === 'application') {
    require __DIR__ . '/native-coverage-composition-application.php';
    if (native_composition_application_only(1) !== 11 || native_composition_application_only(2) !== 22) throw new RuntimeException('The application-only fixture was not executed.');
}
$coverage->stop();
NativeCoverageComposition::write($coverage, $root, $argv[2], $argv[3]);
(new \SebastianBergmann\CodeCoverage\Report\Clover())->process($coverage, $argv[3] . '.xml');
