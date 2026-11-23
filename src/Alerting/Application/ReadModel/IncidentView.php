<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Alerting\Application\ReadModel;

use Kadupul\Alerting\Domain\Incident\Incident;

/**
 * An incident with the series it opened on and its rule. $incident is null
 * for a stored row the domain refuses, which the page reports by id instead
 * of hiding. $series is the series recorded when the incident opened, which
 * decides who may see the incident. $rule is null once the rule is deleted
 * or when its current series is one the viewer cannot see; $ruleId is
 * shown instead. $acknowledgedBy is the acknowledging account's username, or
 * null when that account is gone.
 */
final readonly class IncidentView
{
    public function __construct(
        public int $id,
        public ?Incident $incident,
        public SeriesView $series,
        public int $ruleId,
        public ?RuleView $rule,
        public ?string $acknowledgedBy,
    ) {}
}
