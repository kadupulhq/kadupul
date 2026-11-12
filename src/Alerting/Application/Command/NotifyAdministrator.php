<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Alerting\Application\Command;

use Kadupul\Alerting\Application\Port\AdministrativeMailDelivery;
use Kadupul\Alerting\Application\Port\AdministratorNotificationPreferences;
use Kadupul\Alerting\Application\Port\AdministratorRecipients;
use Kadupul\Alerting\Domain\AdministratorNotificationStatus;

final readonly class NotifyAdministrator
{
    public function __construct(
        private AdministratorNotificationPreferences $preferences,
        private AdministratorRecipients $recipients,
        private AdministrativeMailDelivery $delivery,
    ) {}

    public function __invoke(string $subject, string $html): AdministratorNotificationStatus
    {
        $policy = $this->preferences->policy();
        if (($suppression = $policy->suppression()) !== null) {
            return $suppression;
        }
        $recipient = $this->recipients->find($policy->administratorId);
        if ($recipient === null) {
            return AdministratorNotificationStatus::InvalidAccount;
        }
        if ($recipient->email === '') {
            return AdministratorNotificationStatus::MissingAddress;
        }
        $this->delivery->send($recipient, $subject, $html);

        return AdministratorNotificationStatus::Sent;
    }
}
