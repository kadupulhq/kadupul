<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Alerting\Application\Query;

use Kadupul\Alerting\Application\Port\AlertingSettings;
use Kadupul\Alerting\Application\Port\RateSource;

/**
 * The notice the rule and incident pages show. The pages are reachable
 * whatever the switch says, so administrators can prepare rules before they
 * turn alerting on; the notice says why nothing is evaluated.
 */
final readonly class ViewAlertingStatus
{
    public function __construct(private AlertingSettings $settings, private RateSource $rates) {}

    public function __invoke(): AlertingStatus
    {
        try {
            if (!$this->settings->current()->enabled) {
                return AlertingStatus::Disabled;
            }

            return $this->rates->available() ? AlertingStatus::Active : AlertingStatus::RateSourceUnavailable;
        } catch (\Throwable) {
            // A failed read is reported as such, never as active or as off.
            return AlertingStatus::Unknown;
        }
    }
}
