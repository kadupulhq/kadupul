<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Platform\Contract;

/**
 * Writes one level-less line as cacti_log($message, false, $environ) does, to
 * the destinations the main database's log settings name. It works without
 * the legacy bootstrap, so a console command can reach cacti.log. Best effort:
 * a failed write never throws.
 */
interface OperatorLog
{
    /**
     * @param string $environ the cacti.log category, such as ALERTING; never POLLER
     * @param string $message prefixed ERROR:, WARNING: or NOTICE: as cacti_log() callers do
     */
    public function record(string $environ, string $message): void;
}
