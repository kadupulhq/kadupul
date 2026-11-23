<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Alerting\Application\Port;

/**
 * A stored record of one rule that evaluation cannot use: a rule or state row
 * the domain refuses, or an incident row that disagrees with the rule's state
 * (ADR 0011 decision 8). It concerns that rule alone, so the evaluator skips
 * and reports the rule and carries on. Any other exception, such as a lost
 * connection or a failed rate read, is not about one rule and stops the run.
 *
 * The reason names columns, never stored values.
 */
final class UnusableRuleRecord extends \RuntimeException
{
    public function __construct(public readonly int $ruleId, public readonly string $reason, ?\Throwable $previous = null)
    {
        parent::__construct('Alert rule ' . $ruleId . ' cannot be evaluated: ' . $reason, 0, $previous);
    }
}
