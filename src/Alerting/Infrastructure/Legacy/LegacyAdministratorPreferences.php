<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Alerting\Infrastructure\Legacy;

use Kadupul\Alerting\Application\Port\AdministratorNotificationPreferences;
use Kadupul\Alerting\Domain\AdministratorNotificationPolicy;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

#[AsAlias(AdministratorNotificationPreferences::class)]
final readonly class LegacyAdministratorPreferences implements AdministratorNotificationPreferences
{
    public function policy(): AdministratorNotificationPolicy
    {
        $administrator = \read_config_option('admin_user');

        return new AdministratorNotificationPolicy(
            is_numeric($administrator) ? (int) $administrator : 0,
            \read_config_option('notify_admin') === 'on',
        );
    }
}
