<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Alerting\Application\Port;

/**
 * Runs one rule's writes so they commit together or not at all (ADR 0011
 * decisions 6 and 7). The rule state, the incident change and, from Task 4,
 * the notification row go through the same runner. An exception from the
 * work rolls every write back and is rethrown. A transaction the caller
 * already holds is not committed here.
 */
interface TransactionRunner
{
    /** @param \Closure(): void $work */
    public function run(\Closure $work): void;
}
