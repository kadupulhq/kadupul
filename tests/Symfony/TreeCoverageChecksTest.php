<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/Helpers/PhpSource.php';

final class TreeCoverageChecksTest extends TestCase
{
    private const array CHECKS = [
        'tree CLI creates a node under an existing header in its tree',
        'tree CLI permits root placement',
        'tree CLI rejects a nonexistent parent without inserting a node',
        'tree CLI rejects a parent from another tree without inserting a node',
        'tree CLI rejects a graph item as a parent without inserting a node',
        'tree CLI rejects host site and empty header parents without writes',
        'tree CLI rejects missing tree and malformed parent grammar without writes',
        'tree CLI stores the complete site identity without a copied title',
        'tree CLI site placement preserves a valid header and rejects duplicates',
        'site tree nodes render renamed site identity and current devices',
        'tree CLI rejects invalid site identity with a diagnostic and no writes',
        'tree API rejects all non-header parents and preserves rejected updates',
    ];

    public static function setUpBeforeClass(): void
    {
        $source = file_get_contents(__DIR__ . '/merge_coverage.php');
        self::assertIsString($source);
        eval(test_php_function_source($source, 'require_symfony_integration_checks'));
    }

    public function testCompleteRequiredBehaviorChecksAreAdmitted(): void
    {
        require_symfony_integration_checks(['checks' => self::CHECKS], self::CHECKS);
        self::addToAssertionCount(1);
    }

    public static function omittedChecks(): iterable
    {
        foreach (self::CHECKS as $index => $check) {
            yield $check => [$index];
        }
    }

    #[DataProvider('omittedChecks')]
    public function testEveryMissingTreeBehaviorCheckRefusesEvidence(int $index): void
    {
        $checks = self::CHECKS;
        unset($checks[$index]);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Incomplete Symfony integration checks');
        require_symfony_integration_checks(['checks' => array_values($checks)], self::CHECKS);
    }
}
