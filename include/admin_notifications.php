<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/** Bridge the legacy caller into the Symfony-owned Alerting use case. */
function kadupul_notify_administrator(string $subject, string $html): void
{
    try {
        $kernel = require dirname(__DIR__) . '/config/bootstrap.php';
        try {
            $kernel->boot();
            $kernel->getContainer()->get(\Kadupul\Alerting\Infrastructure\Legacy\AdministratorNotificationBridge::class)
                ->send($subject, $html);
        } finally {
            $kernel->shutdown();
        }
    } catch (\Throwable) {
        // Collection must continue, and raw transport errors may contain credentials.
        cacti_log('WARNING: Administrative Email could not be confirmed. Check mail settings and server logs before retrying.', false, 'SYSTEM');
    }
}
