<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Alerting\Application;

final readonly class DeliveryReport
{
    /**
     * @param int $sent     rows the mail port accepted and that were then marked sent
     * @param int $retrying rows whose attempt failed and that are queued for a later one
     * @param int $failed   rows marked failed: out of attempts, unreadable, or a claim that expired on the last attempt
     * @param int $lost     rows whose claim expired during the attempt and that another deliverer now holds
     */
    public function __construct(
        public int $sent = 0,
        public int $retrying = 0,
        public int $failed = 0,
        public int $lost = 0,
    ) {}
}
