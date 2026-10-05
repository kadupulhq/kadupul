<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Infrastructure\Legacy;

use Closure;
use Kadupul\Platform\Contract\ReferenceWriteTransactionRunner;
use PDO;

/** Preserve the selected PDO and existing native transaction outcomes. */
final class NativeReferenceWriteTransactionRunner implements ReferenceWriteTransactionRunner
{
    public function run(PDO $connection, Closure $operation, array $tables): mixed
    {
        return (new LegacyReferenceWriteTransaction($connection))->run($operation, $tables);
    }
}
