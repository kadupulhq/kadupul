<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Kadupul\Tests\AuditCoverage;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

require_once dirname(__DIR__) . '/Helpers/PhpSource.php';

final class AuditCoverageChecksTest extends TestCase
{
    private const array CHECKS = [
        'audit report with the audit schema missing: frozen original records its historical success exit on baseline failure',
        'audit report with the audit schema missing: native command fails without claiming a clean audit',
        'audit report with the audit schema missing: refused native audit preserves all schema and baseline state',
        'audit report with an unparsable audit schema: frozen original records its historical success exit on baseline failure',
        'audit report with an unparsable audit schema: native command fails without claiming a clean audit',
        'audit report with an unparsable audit schema: refused native audit preserves all schema and baseline state',
        'audit report when table_columns cannot be created: frozen original records its historical success exit on baseline failure',
        'audit report when table_columns cannot be created: native command fails without claiming a clean audit',
        'audit report when table_columns cannot be created: refused native audit preserves all schema and baseline state',
    ];

    public static function setUpBeforeClass(): void
    {
        $source = file_get_contents(__DIR__ . '/merge_coverage.php');
        self::assertIsString($source);
        eval('namespace ' . __NAMESPACE__ . '; use \RuntimeException;' . test_php_function_source($source, 'require_symfony_integration_checks'));
        eval('namespace ' . __NAMESPACE__ . '; use \RuntimeException;' . test_php_function_source($source, 'required_audit_baseline_failure_checks'));
    }

    public function testCompleteAuditBaselineFailureOutcomesAreAdmitted(): void
    {
        self::assertSame(self::CHECKS, required_audit_baseline_failure_checks());
        require_symfony_integration_checks(['checks' => self::CHECKS], required_audit_baseline_failure_checks());
        self::addToAssertionCount(1);
    }

    public static function omittedChecks(): iterable
    {
        foreach (self::CHECKS as $index => $check) {
            yield $check => [$index];
        }
    }

    #[DataProvider('omittedChecks')]
    public function testEveryMissingAuditBaselineFailureOutcomeRefusesEvidence(int $index): void
    {
        $checks = self::CHECKS;
        unset($checks[$index]);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Incomplete Symfony integration checks');
        require_symfony_integration_checks(['checks' => array_values($checks)], required_audit_baseline_failure_checks());
    }
}
