<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Tests\Monitoring;

use Kadupul\Monitoring\Domain\InvalidVersionConstraint;
use Kadupul\Monitoring\Domain\SemanticVersion;
use Kadupul\Monitoring\Domain\VersionConstraint;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PackVersionTest extends TestCase
{
    /** @return iterable<array{string, string, int}> */
    public static function precedence(): iterable
    {
        yield ['1.0.0', '1.0.0', 0];
        yield ['1.0.0', '1.0.1', -1];
        yield ['1.10.0', '1.9.0', 1];
        yield ['2.0.0', '1.99.99', 1];
        yield ['1.0.0-alpha', '1.0.0', -1];
        yield ['1.0.0-alpha', '1.0.0-alpha.1', -1];
        yield ['1.0.0-alpha.1', '1.0.0-alpha.beta', -1];
        yield ['1.0.0-beta.2', '1.0.0-beta.11', -1];
        yield ['1.0.0-rc.1', '1.0.0', -1];
        // Build metadata does not affect precedence.
        yield ['1.0.0+build.5', '1.0.0+build.9', 0];
    }

    #[DataProvider('precedence')]
    public function testSemanticVersionPrecedence(string $a, string $b, int $expected): void
    {
        self::assertSame($expected, SemanticVersion::parse($a)->compare(SemanticVersion::parse($b)));
        self::assertSame(-$expected, SemanticVersion::parse($b)->compare(SemanticVersion::parse($a)));
    }

    public function testASemanticVersionNeedsThreeParts(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        SemanticVersion::parse('1.0');
    }

    /** @return iterable<array{string, string, bool}> */
    public static function constraints(): iterable
    {
        yield ['>=1.0 <2.0', '1.2.35', true];
        yield ['>=1.0 <2.0', '2.0.0', false];
        yield ['>=1.0,<2.0', '1.0.0', true];
        yield ['>= 1.3', '1.2.35', false];
        yield ['^1.2', '1.9.0', true];
        yield ['^1.2', '2.0.0', false];
        yield ['^1.2', '1.1.9', false];
        yield ['^0.3', '0.3.9', true];
        yield ['^0.3', '0.4.0', false];
        yield ['~1.2', '1.9.0', true];
        yield ['~1.2.3', '1.2.9', true];
        yield ['~1.2.3', '1.3.0', false];
        yield ['1.2.*', '1.2.35', true];
        yield ['1.2.*', '1.3.0', false];
        yield ['*', '1.2.35', true];
        yield ['1.0 - 1.2', '1.2.35', true];
        yield ['1.0 - 1.2.0', '1.2.35', false];
        yield ['<1.0 || >=1.2', '1.2.35', true];
        yield ['<1.0 | >=1.3', '1.2.35', false];
        yield ['!=1.2.35', '1.2.35', false];
        yield ['1.2.35', '1.2.35', true];
        // As in Composer, a < or >= bound without a stability means the dev
        // build, so <2.0 excludes 2.0.0-beta and >=1.3 admits 1.3.0-beta.
        yield ['<2.0', '2.0.0-beta', false];
        yield ['>=1.3', '1.3.0-beta', true];
        yield ['>1.2.35', '1.3.0-dev', true];
        yield ['<=1.3', '1.3.0-dev', true];
    }

    #[DataProvider('constraints')]
    public function testVersionConstraints(string $constraint, string $version, bool $expected): void
    {
        self::assertSame($expected, VersionConstraint::parse($constraint)->isSatisfiedBy($version));
    }

    /** @return iterable<array{string}> */
    public static function unparseable(): iterable
    {
        yield [''];
        yield ['>=one'];
        yield ['>=1.0 <'];
        yield ['^'];
        yield ['1.0 -'];
    }

    #[DataProvider('unparseable')]
    public function testUnparseableConstraintsAreRejected(string $constraint): void
    {
        $this->expectException(InvalidVersionConstraint::class);

        VersionConstraint::parse($constraint);
    }
}
