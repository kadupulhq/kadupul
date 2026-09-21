<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Alerting\Infrastructure\Legacy;

use Kadupul\Alerting\Application\Port\AdministratorNotificationPreferences;
use Kadupul\Alerting\Domain\AdministratorNotificationPolicy;

final class LegacyAdministratorPreferences implements AdministratorNotificationPreferences
{
    public function policy(): AdministratorNotificationPolicy
    {
        return new AdministratorNotificationPolicy((int) \read_config_option('admin_user'), \read_config_option('notify_admin') === 'on');
    }
}
