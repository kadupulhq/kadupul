<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-2.0-or-later

namespace Kadupul\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/NativeSessionCoverageRegistration.php';

final class NativeSessionCoverageRegistrationTest extends TestCase
{
    public static function missingExecution(): iterable
    {
        foreach (NativeSessionCoverageRegistration::MEASURED as $source) {
            yield $source . '/omitted' => [$source, true];
            yield $source . '/unexecuted' => [$source, false];
        }
    }

    #[DataProvider('missingExecution')]
    public function testGlobalMergerRejectsMissingNativeExecution(string $source, bool $omit): void
    {
        $root = dirname(__DIR__, 2);
        $lines = [];
        foreach (NativeSessionCoverageRegistration::MEASURED as $path) {
            $lines[realpath($root . '/' . $path)] = [1 => ['native-regression']];
        }
        NativeSessionCoverageRegistration::requireMeasured($lines, $root);
        $path = realpath($root . '/' . $source);
        if ($omit) {
            unset($lines[$path]);
        } else {
            $lines[$path] = [1 => [], 2 => null];
        }
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Missing measured native session execution: ' . $source);
        NativeSessionCoverageRegistration::requireMeasured($lines, $root);
    }
}
