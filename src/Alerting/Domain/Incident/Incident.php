<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Alerting\Domain\Incident;

/**
 * One incident of a rule. Times are Unix seconds: the opening and recovery
 * times are the observation times that caused them, an orphaned incident ends
 * at the evaluator's clock. An acknowledgement stays recorded after the
 * incident ends.
 */
final readonly class Incident
{
    public function __construct(
        public int $id,
        public int $ruleId,
        public IncidentStatus $status,
        public int $openedAt,
        public ?int $endedAt = null,
        public ?int $acknowledgedAt = null,
        public ?int $acknowledgedBy = null,
    ) {
        if ($id < 1 || $ruleId < 1) {
            throw new InvalidIncident('An incident needs an id and a rule id of at least 1.');
        }
        foreach ([$openedAt, $endedAt, $acknowledgedAt, $acknowledgedBy] as $value) {
            if ($value !== null && $value < 0) {
                throw new InvalidIncident('Incident ' . $id . ' has a negative time or user id.');
            }
        }
        if (($acknowledgedAt === null) !== ($acknowledgedBy === null)) {
            throw new InvalidIncident('Incident ' . $id . ' records an acknowledgement without both its time and its user.');
        }
        if ($status->isActive() === ($endedAt !== null)) {
            throw new InvalidIncident('Incident ' . $id . ' is ' . $status->value . ($endedAt === null ? ' with no end time.' : ' but has an end time.'));
        }
        if (($status === IncidentStatus::Acknowledged) !== ($acknowledgedAt !== null) && $status->isActive()) {
            throw new InvalidIncident('Incident ' . $id . ' is ' . $status->value . ($acknowledgedAt === null ? ' with no acknowledgement.' : ' but records an acknowledgement.'));
        }
    }

    public function isActive(): bool
    {
        return $this->status->isActive();
    }
}
