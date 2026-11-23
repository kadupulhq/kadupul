<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Alerting\Infrastructure\Dbal;

use Doctrine\DBAL\Connection;
use Kadupul\Alerting\Application\Port\TransactionRunner;

/**
 * A transaction on the connection the alerting stores write through, so they
 * must all be built with this same connection. When the caller already holds
 * a transaction, DBAL runs the work in a savepoint: a failure rolls back only
 * the work, and success leaves the commit to the caller.
 */
final readonly class DbalTransactionRunner implements TransactionRunner
{
    public function __construct(private Connection $database) {}

    public function run(\Closure $work): void
    {
        $this->database->transactional(static function () use ($work): void {
            $work();
        });
    }
}
