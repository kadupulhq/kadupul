<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Alerting\Application\Port;

use Kadupul\Alerting\Domain\AdministratorNotificationPolicy;

interface AdministratorNotificationPreferences
{
    public function policy(): AdministratorNotificationPolicy;
}
