<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Infrastructure\Legacy;

use Doctrine\DBAL\Exception\DriverException;

/**
 * db_execute_prepared()'s retry after a deadlock or lock wait timeout, for a
 * single statement run through DBAL on the legacy session. LegacyDatabaseCall
 * never retries, because its operation may hold several statements; this is
 * only ever wrapped around one.
 *
 * The caller says whether to retry, and must say no inside a transaction: the
 * server may already have rolled back the earlier statements, so repeating
 * the last one alone would commit half of the work. The helper also retries
 * only when it logs and is not in strict mode, since its retry sits in the
 * logging branch after the strict-mode throw. Counts and pause are its own.
 */
final readonly class LegacyLockRetry
{
    /** Deadlock, lock wait timeout, and "record has changed since last read". */
    private const array CODES = [1213, 1205, 1020];

    private const int RETRIES = 30;

    private const int PAUSE_MICROSECONDS = 200000;

    /** @param (\Closure(int): void)|null $pause test seam with usleep()'s signature */
    public function __construct(private ?\Closure $pause = null) {}

    /**
     * @template T
     *
     * @param \Closure(): T $statement one statement
     *
     * @return T
     */
    public function run(bool $retry, \Closure $statement): mixed
    {
        for ($failures = 0; ;) {
            try {
                return $statement();
            } catch (DriverException $exception) {
                if (!$retry || !in_array($exception->getCode(), self::CODES, true) || ++$failures > self::RETRIES) {
                    throw $exception;
                }
                if ($this->pause !== null) {
                    ($this->pause)(self::PAUSE_MICROSECONDS);
                } else {
                    usleep(self::PAUSE_MICROSECONDS);
                }
            }
        }
    }
}
