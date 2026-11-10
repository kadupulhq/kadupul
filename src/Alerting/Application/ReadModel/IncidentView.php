<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Alerting\Application\ReadModel;

use Kadupul\Alerting\Domain\Incident\Incident;

/**
 * An incident with the rule and series it belongs to. $incident is null for
 * a stored row the domain refuses, which the page reports by id instead of
 * hiding. $rule is null once the rule is deleted. $acknowledgedBy is the
 * acknowledging account's username, or null when that account is gone.
 */
final readonly class IncidentView
{
    public function __construct(
        public int $id,
        public ?Incident $incident,
        public ?RuleView $rule,
        public ?string $acknowledgedBy,
    ) {}
}
