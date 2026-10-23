<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Monitoring\Domain;

/**
 * One device's instruction to a collector for one pack, as defined by
 * docs/schemas/collection-plan.schema.json. Immutable: a status change
 * returns a new plan, so replanning can never alter an active one in place.
 */
final readonly class CollectionPlan
{
    public \DateTimeImmutable $generatedAt;

    /** @param list<PlanTarget> $targets */
    public function __construct(
        public string $planId,
        \DateTimeImmutable $generatedAt,
        public int $deviceId,
        public PlanStatus $status,
        public string $packName,
        public string $packVersion,
        public array $targets,
        public string $dedupKey,
    ) {
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/D', $planId) !== 1) {
            throw new InvalidCollectionPlan(sprintf('Plan id "%s" is not a lowercase UUID.', $planId));
        }
        if ($deviceId < 1) {
            throw new InvalidCollectionPlan('A plan needs a positive device id.');
        }
        if ($targets === []) {
            throw new InvalidCollectionPlan('A plan needs at least one target.');
        }
        if (preg_match('/^[0-9a-f]{64}$/D', $dedupKey) !== 1) {
            throw new InvalidCollectionPlan('A dedup key is a lowercase hex SHA-256.');
        }
        SemanticVersion::parse($packVersion);
        // Second precision: the schema's date-time is emitted without fractions.
        $utc = $generatedAt->setTimezone(new \DateTimeZone('UTC'));
        $this->generatedAt = $utc->setTime((int) $utc->format('H'), (int) $utc->format('i'), (int) $utc->format('s'));
    }

    /** @param list<PlanTarget> $targets */
    public static function propose(int $deviceId, MonitoringPack $pack, array $targets, \DateTimeImmutable $generatedAt): self
    {
        $dedupKey = hash('sha256', json_encode(
            [1, $deviceId, $pack->name, $pack->version->toString(), array_map(static fn(PlanTarget $t): array => $t->canonical(), $targets)],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION,
        ));
        $utc = $generatedAt->setTimezone(new \DateTimeZone('UTC'));
        // A name-based UUID (RFC 9562 version 8) of content and time: a fresh
        // id for every revision, and the same id when the same plan is made
        // at the same instant, so a retried planning run does not fork history.
        $hex = substr(hash('sha256', $dedupKey . '|' . $utc->format('Y-m-d\TH:i:s\Z')), 0, 32);
        $hex[12] = '8';
        $hex[16] = '89ab'[hexdec($hex[16]) & 3];
        $planId = sprintf('%s-%s-%s-%s-%s', substr($hex, 0, 8), substr($hex, 8, 4), substr($hex, 12, 4), substr($hex, 16, 4), substr($hex, 20, 12));

        return new self($planId, $utc, $deviceId, PlanStatus::Proposed, $pack->name, $pack->version->toString(), $targets, $dedupKey);
    }

    public function withStatus(PlanStatus $next): self
    {
        if (!$this->status->canMoveTo($next)) {
            throw new PlanTransitionRejected(sprintf('Plan %s cannot move from %s to %s.', $this->planId, $this->status->value, $next->value));
        }

        return new self($this->planId, $this->generatedAt, $this->deviceId, $next, $this->packName, $this->packVersion, $this->targets, $this->dedupKey);
    }

    /** @return list<string> metric ids in target order, each once */
    public function metricIds(): array
    {
        return array_values(array_unique(array_merge(...array_map(
            static fn(PlanTarget $t): array => array_map(static fn(PlannedMetric $m): string => $m->id->toString(), $t->metrics),
            $this->targets,
        ))));
    }

    /** @return list<string> "protocol:metric id" for each metric, so a transport change shows as a difference */
    public function metricRoutes(): array
    {
        $routes = [];
        foreach ($this->targets as $target) {
            foreach ($target->metrics as $metric) {
                $routes[] = $target->protocol . ':' . $metric->id->toString();
            }
        }

        return array_values(array_unique($routes));
    }
}
