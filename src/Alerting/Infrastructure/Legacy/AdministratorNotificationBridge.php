<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Alerting\Infrastructure\Legacy;

use Kadupul\Alerting\Application\Command\NotifyAdministrator;
use Kadupul\Alerting\Domain\AdministratorNotificationStatus;

/** Public Symfony service used only by the legacy admin_email entry point. */
final class AdministratorNotificationBridge
{
    public function __construct(private readonly NotifyAdministrator $notify) {}

    public function send(string $subject, string $html): void
    {
        $warning = match (($this->notify)($subject, $html)) {
            AdministratorNotificationStatus::Sent => null,
            AdministratorNotificationStatus::NotConfigured => 'Primary Admin account not set!',
            AdministratorNotificationStatus::Disabled => 'Primary Admin account notifications disabled!',
            AdministratorNotificationStatus::InvalidAccount => 'Primary Admin account set to an invalid user!',
            AdministratorNotificationStatus::MissingAddress => 'Primary Admin account does not have an email address!',
        };
        if ($warning !== null) {
            \cacti_log('WARNING: ' . $warning . '  Unable to send administrative Email.', false, 'SYSTEM');
        }
    }
}
