<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Platform\Infrastructure\Legacy;

use Doctrine\DBAL\Connection;
use Kadupul\Platform\Contract\OperatorLog;

/** cacti_log() on the main database's log settings, for modules outside Platform. */
final readonly class MainDatabaseOperatorLog implements OperatorLog
{
    public function __construct(private Connection $database, private LegacyOperatorLog $log) {}

    public function record(string $environ, string $message): void
    {
        $this->log->record($this->database, $environ, $message);
    }
}
