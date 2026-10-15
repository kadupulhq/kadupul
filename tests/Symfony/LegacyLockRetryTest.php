<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Doctrine\DBAL\Exception\DeadlockException;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Exception\LockWaitTimeoutException;
use Doctrine\DBAL\Exception\TableNotFoundException;
use Kadupul\Platform\Infrastructure\Legacy\Dbal\SharedPdoFailure;
use Kadupul\Platform\Infrastructure\Legacy\LegacyLockRetry;
use PHPUnit\Framework\TestCase;

/**
 * The retry bounds of db_execute_prepared(): 30 retries after the first
 * failure, 200 ms apart. tests/integration/SettingsStorageDatabaseTest proves
 * a real lock wait on MariaDB and MySQL.
 */
final class LegacyLockRetryTest extends TestCase
{
    /** @var list<int> */
    private array $pauses = [];

    public function testEachLockConflictIsRetriedUntilTheStatementSucceeds(): void
    {
        foreach ([1213 => DeadlockException::class, 1205 => LockWaitTimeoutException::class, 1020 => DriverException::class] as $code => $class) {
            $this->pauses = [];
            $attempts = 0;
            $value = $this->retry()->run(true, function () use (&$attempts, $code, $class): string {
                if (++$attempts < 3) {
                    throw self::failure($class, $code);
                }

                return 'written';
            });
            self::assertSame('written', $value, (string) $code);
            self::assertSame(3, $attempts, (string) $code);
            self::assertSame([200000, 200000], $this->pauses, (string) $code);
        }
    }

    public function testItGivesUpAfterThirtyRetries(): void
    {
        $attempts = 0;
        $failure = self::failure(DeadlockException::class, 1213);
        try {
            $this->retry()->run(true, function () use (&$attempts, $failure): never {
                ++$attempts;
                throw $failure;
            });
            self::fail('The last failure must reach the caller.');
        } catch (DeadlockException $exception) {
            self::assertSame($failure, $exception);
        }
        self::assertSame(31, $attempts);
        self::assertCount(30, $this->pauses);
    }

    public function testNothingIsRetriedWhenTheCallerSaysNo(): void
    {
        $attempts = 0;
        try {
            $this->retry()->run(false, function () use (&$attempts): never {
                ++$attempts;
                throw self::failure(LockWaitTimeoutException::class, 1205);
            });
            self::fail('The failure must reach the caller.');
        } catch (LockWaitTimeoutException) {
        }
        self::assertSame(1, $attempts);
        self::assertSame([], $this->pauses);
    }

    public function testOtherFailuresAreNotRetried(): void
    {
        $attempts = 0;
        try {
            $this->retry()->run(true, function () use (&$attempts): never {
                ++$attempts;
                throw self::failure(TableNotFoundException::class, 1146);
            });
            self::fail('The failure must reach the caller.');
        } catch (TableNotFoundException) {
        }
        self::assertSame(1, $attempts);
        self::assertSame([], $this->pauses);
    }

    private function retry(): LegacyLockRetry
    {
        return new LegacyLockRetry(function (int $microseconds): void {
            $this->pauses[] = $microseconds;
        });
    }

    /** @param class-string<DriverException> $class */
    private static function failure(string $class, int $code): DriverException
    {
        return new $class(new SharedPdoFailure('SQLSTATE[HY000]: error ' . $code, 'HY000', $code), null);
    }
}
