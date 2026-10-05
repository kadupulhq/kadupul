<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Contract;

use Closure;
use PDO;

/** Execute confirmed local writes on the explicitly selected native connection. */
interface ReferenceWriteTransactionRunner
{
    /** @param list<string> $tables */
    public function run(PDO $connection, Closure $operation, array $tables): mixed;
}
