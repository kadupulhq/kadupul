<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Alerting\Application;

final readonly class AlertingRun
{
    /**
     * @param ?EvaluationReport $evaluation set when the outcome is Evaluated
     * @param ?DeliveryReport   $delivery   set when this run drained the queue; null while another deliverer held it
     */
    public function __construct(
        public AlertingRunOutcome $outcome,
        public ?EvaluationReport $evaluation = null,
        public ?DeliveryReport $delivery = null,
    ) {}
}
