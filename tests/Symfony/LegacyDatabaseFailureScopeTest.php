<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Kadupul\Platform\Infrastructure\Legacy\LegacyDatabaseFailureScope;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class LegacyDatabaseFailureScopeTest extends TestCase
{
    public function testNestedScopesRestoreTheOuterFailurePolicy(): void
    {
        self::assertFalse(LegacyDatabaseFailureScope::active());
        self::assertSame('result', LegacyDatabaseFailureScope::run(function (): string {
            self::assertTrue(LegacyDatabaseFailureScope::active());
            self::assertFalse(LegacyDatabaseFailureScope::run(static fn(): bool => false));
            self::assertTrue(LegacyDatabaseFailureScope::active());
            return 'result';
        }));
        self::assertFalse(LegacyDatabaseFailureScope::active());
    }

    public function testExceptionRestoresThePolicyAndPreservesTheOriginalError(): void
    {
        $error = new RuntimeException('operation failed');
        try {
            LegacyDatabaseFailureScope::run(static fn() => throw $error);
            self::fail('The operation error must escape');
        } catch (RuntimeException $caught) {
            self::assertSame($error, $caught);
        }
        self::assertFalse(LegacyDatabaseFailureScope::active());
    }

    public function testCaughtInnerExceptionDoesNotReleaseTheOuterPolicy(): void
    {
        LegacyDatabaseFailureScope::run(function (): void {
            try {
                LegacyDatabaseFailureScope::run(static fn() => throw new RuntimeException('inner'));
            } catch (RuntimeException) {
                self::assertTrue(LegacyDatabaseFailureScope::active());
            }
        });
        self::assertFalse(LegacyDatabaseFailureScope::active());
    }
}
