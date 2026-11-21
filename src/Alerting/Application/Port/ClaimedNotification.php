<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Alerting\Application\Port;

/**
 * A notification row this deliverer holds until $leaseUntil. $attempts
 * includes the attempt the claim started. $content is null when the row or
 * its incident cannot be read; such a row is failed, never sent.
 */
final readonly class ClaimedNotification
{
    public function __construct(
        public int $id,
        public int $attempts,
        public int $leaseUntil,
        public ?NotificationContent $content,
    ) {}
}
