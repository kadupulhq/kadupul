<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Alerting\Application;

enum AlertingRunOutcome
{
    /** alerting_enable is off: nothing was evaluated or sent. */
    case Disabled;
    /** Another process holds the evaluation lock: nothing was evaluated or sent. */
    case Busy;
    /** Evaluation ran; its report may still say the rate source was inactive. */
    case Evaluated;
}
