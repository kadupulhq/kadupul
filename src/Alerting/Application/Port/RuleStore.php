<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Alerting\Application\Port;

use Kadupul\Alerting\Application\Rule\RuleDefinition;
use Kadupul\Alerting\Application\Rule\RuleSubmissionRefused;
use Kadupul\Alerting\Application\Query\AlertingAccessDenied;

/**
 * Rule writes. Each runs in one audited transaction on the evaluator's
 * connection that rechecks the actor first (RuleAccess::assertCurrent()) and
 * then takes the evaluator's lock order: the alert_rule row, then its state,
 * then its incident (ADR 0011 decision 7). The series must exist and be one
 * the actor can view when the rule is saved (decision 12).
 *
 * Deleting a rule, or saving it with another series or kind, ends its rule
 * the way decision 6 ends an orphan, in the same transaction: the state row
 * is deleted and an open or acknowledged incident becomes orphaned, with no
 * notification. Its queued notifications are kept and still sent. Other
 * changes keep the state and incident.
 */
interface RuleStore
{
    public const string SERIES_UNAVAILABLE = 'No data source with that ID and RRD data source name exists that you can view.';
    public const string STALE = 'The rule changed since you opened this form. Reload before saving.';
    public const string NOT_FOUND = 'Alert rule not found.';

    /**
     * @throws RuleSubmissionRefused SERIES_UNAVAILABLE
     * @throws AlertingAccessDenied
     */
    public function create(int $actorId, RuleDefinition $rule): int;

    /** @throws RuleSubmissionRefused SERIES_UNAVAILABLE, STALE or NOT_FOUND */
    public function update(int $actorId, int $ruleId, string $revision, RuleDefinition $rule): void;

    /** @throws RuleSubmissionRefused STALE or NOT_FOUND */
    public function delete(int $actorId, int $ruleId, string $revision): void;
}
