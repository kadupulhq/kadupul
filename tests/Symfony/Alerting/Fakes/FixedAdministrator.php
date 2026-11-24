<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Tests\Alerting\Fakes;

use Kadupul\Alerting\Application\Port\AdministratorNotificationPreferences;
use Kadupul\Alerting\Application\Port\AdministratorRecipients;
use Kadupul\Alerting\Domain\AdministratorNotificationPolicy;
use Kadupul\Alerting\Domain\AdministratorRecipient;

/** One administrator, account 1, with notifications on unless a test changes the policy. */
final class FixedAdministrator implements AdministratorNotificationPreferences, AdministratorRecipients
{
    public AdministratorNotificationPolicy $policy;

    public function __construct(public AdministratorRecipient $recipient = new AdministratorRecipient('admin@example.test', 'Admin'))
    {
        $this->policy = new AdministratorNotificationPolicy(1, true);
    }

    public function policy(): AdministratorNotificationPolicy
    {
        return $this->policy;
    }

    public function find(int $id): ?AdministratorRecipient
    {
        return $id === 1 ? $this->recipient : null;
    }
}
