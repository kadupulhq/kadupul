<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../Helpers/PhpSource.php';
require_once __DIR__ . '/../../../Helpers/PestCodeCoverageCompatibility.php';
require_once __DIR__ . '/../../../Helpers/GraphPolicyDisplayCoverageRegistration.php';

final class GraphPolicyDisplaySqlParityTest extends TestCase
{
    use PestCodeCoverageCompatibility;
    private static bool $evidenceChecked = false;

    #[DataProvider('methods')]
    public function testDisplayedPolicyMatchesPersistedSqlForUserAndGroupDefaultsAndExceptions(int $method): void
    {
        $root = dirname(__DIR__, 4);
        $directory = sys_get_temp_dir() . '/graph-policy-display-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($directory, 0700));
        $scenario = json_encode(['operation' => 'graph-policy-display', 'config' => ['graph_auth_method' => $method]], JSON_THROW_ON_ERROR);
        $coverage = $this->getTestResultObject()->getCodeCoverage();
        $command = [PHP_BINARY, '-d', 'auto_prepend_file=', '-d', 'pcov.directory=' . $root,
            '-d', 'pcov.exclude=~/(include/vendor|tests)/~', $root . '/tests/Fixtures/auth-policy-native.php', $scenario, $directory];
        if ($coverage !== null) $command[] = 'coverage';
        try {
            ['out' => $output, 'err' => $error, 'status' => $status] = test_php_run($command);
            self::assertSame(0, $status, $error . $output);
            self::assertSame('', $error);
            if ($coverage !== null) {
                require_once $root . '/tests/Helpers/NativeChildCoverageEvidence.php';
                $reports = glob($directory . '/*.coverage');
                self::assertCount(1, $reports);
                $child = NativeChildCoverageEvidence::load(
                    $reports[0],
                    $root,
                    'tests/Fixtures/auth-policy-native.php',
                    $scenario,
                    GraphPolicyDisplayCoverageRegistration::SOURCES,
                    GraphPolicyDisplayCoverageRegistration::MARKERS,
                    ['lib/auth.php']
                );
                if (!self::$evidenceChecked) {
                    self::assertSame(33, NativeChildCoverageEvidence::verifyRejections(
                        $reports[0],
                        $root,
                        'tests/Fixtures/auth-policy-native.php',
                        $scenario,
                        GraphPolicyDisplayCoverageRegistration::SOURCES,
                        GraphPolicyDisplayCoverageRegistration::MARKERS,
                        ['lib/auth.php'],
                        'lib/rrd.php'
                    ));
                    self::$evidenceChecked = true;
                }
                $coverage->merge($child);
            }
            $rows = json_decode($output, true, 512, JSON_THROW_ON_ERROR)['result'];
            self::assertCount(4096, $rows);
            $granted = 0;
            $restrictedExamples = [];
            foreach ($rows as $row) {
                $context = json_encode([$method, $row['defaults'], $row['exceptions']], JSON_THROW_ON_ERROR);
                self::assertSame($row['allowed'], $row['display'], 'Displayed policy differs from get_allowed_graphs: ' . $context);
                self::assertSame($row['filtered'], $row['display'], 'Displayed policy differs from get_policy_where: ' . $context);
                $granted += (int) $row['display'];
                if ($row['defaults'] === [7, 7]) $restrictedExamples[implode(',', $row['exceptions'])] = $row['display'];
            }
            self::assertGreaterThan(0, $granted);
            self::assertLessThan(4096, $granted);
            if ($method === 2) {
                self::assertFalse($restrictedExamples['4,0'], 'A template-only grant cannot admit a default-deny restrictive graph.');
                self::assertTrue($restrictedExamples['6,0'], 'Device and template grants in one policy must admit the graph.');
                self::assertFalse($restrictedExamples['2,4'], 'Grants split across user and group cannot satisfy one restrictive policy.');
            }
        } finally {
            foreach (glob($directory . '/*') as $file) unlink($file);
            rmdir($directory);
        }
    }

    public static function methods(): iterable
    {
        yield 'permissive' => [1];
        yield 'restrictive' => [2];
        yield 'device' => [3];
        yield 'graph template' => [4];
    }
}
