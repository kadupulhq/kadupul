<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Alerting\Application\Query;

/** What the alerting pages say about evaluation (ADR 0011 decisions 9 and 10). */
enum AlertingStatus
{
    case Active;
    /** alerting_enable is off: rules can be prepared but nothing is evaluated. */
    case Disabled;
    /** dsstats is off or its tables are missing: "inactive: rate source unavailable". */
    case RateSourceUnavailable;
    /** The settings or the rate source could not be read. */
    case Unknown;
}
