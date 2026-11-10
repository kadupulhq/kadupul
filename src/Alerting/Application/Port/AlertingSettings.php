<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Alerting\Application\Port;

use Kadupul\Alerting\Application\AlertingConfiguration;

interface AlertingSettings
{
    /**
     * The stored alerting_enable, alerting_batch_size and alerting_time_budget.
     * A missing switch is off; a missing or unusable bound is its default.
     * A database that cannot be read is an exception, never "off".
     */
    public function current(): AlertingConfiguration;
}
