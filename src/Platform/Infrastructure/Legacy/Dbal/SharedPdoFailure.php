<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Infrastructure\Legacy\Dbal;

use Doctrine\DBAL\Driver\AbstractException;

/**
 * A driver exception, so DBAL converts it through the MySQL exception
 * converter exactly as it would one raised by its own PDO driver.
 */
final class SharedPdoFailure extends AbstractException
{
    public static function from(\PDOException $exception): self
    {
        // errorInfo carries the server's native error number, which the
        // converter and LegacyDatabaseCall key on; getCode() is the SQLSTATE.
        if ($exception->errorInfo !== null) {
            [$sqlState, $code] = $exception->errorInfo + [null, 0];

            return new self($exception->getMessage(), $sqlState, (int) $code, $exception);
        }

        return new self($exception->getMessage(), null, 0, $exception);
    }
}
