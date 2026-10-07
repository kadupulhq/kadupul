<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Application\Command;

/**
 * audit_database.php ran only on the main data collector. The use case
 * refuses a remote collector itself, so a caller that skips
 * AuditDatabase::refusesThisCollector() still cannot audit or alter the
 * collector's own database.
 */
final class RemoteCollectorRefused extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('The audit runs on the main data collector only.');
    }
}
