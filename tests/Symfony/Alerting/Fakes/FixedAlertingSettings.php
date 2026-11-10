<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Tests\Alerting\Fakes;

use Kadupul\Alerting\Application\AlertingConfiguration;
use Kadupul\Alerting\Application\Port\AlertingSettings;

final class FixedAlertingSettings implements AlertingSettings
{
    public int $reads = 0;

    public function __construct(public AlertingConfiguration $configuration) {}

    public function current(): AlertingConfiguration
    {
        ++$this->reads;

        return $this->configuration;
    }
}
