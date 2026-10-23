<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Monitoring\Application;

use Kadupul\Monitoring\Application\Port\BindingRepository;
use Kadupul\Monitoring\Application\Port\WriterOwnershipProbe;
use Kadupul\Monitoring\Contract\BindingCatalog;
use Kadupul\Monitoring\Contract\BindingResolution;
use Kadupul\Monitoring\Contract\ResolutionStatus;
use Kadupul\Monitoring\Contract\RrdDataSourceType;
use Kadupul\Monitoring\Contract\StorageReference;
use Kadupul\Monitoring\Contract\WriterOwnership;
use Kadupul\Monitoring\Domain\BindingKey;
use Kadupul\Monitoring\Domain\InvalidBinding;
use Kadupul\Monitoring\Domain\MetricBinding;
use Kadupul\Telemetry\Contract\MetricId;
use Kadupul\Telemetry\Contract\ResourceId;

/**
 * Resolves a logical series to its approved binding and reports who writes
 * the field. It reads only; ownership is reported as found and never
 * changed, so resolving cannot hand a legacy field to model-driven writes.
 */
final class ResolveBinding implements BindingCatalog
{
    public function __construct(
        private readonly BindingRepository $bindings,
        private readonly WriterOwnershipProbe $owners,
    ) {}

    public function resolve(int $deviceId, MetricId $metric, ResourceId $resource, string $protocol): BindingResolution
    {
        $unresolved = static fn(ResolutionStatus $status, string $reason, ?string $bindingId = null): BindingResolution
            => new BindingResolution($status, $deviceId, $metric, $resource, $protocol, $reason, $bindingId);
        try {
            $key = new BindingKey($deviceId, $metric, $resource, $protocol);
        } catch (InvalidBinding $e) {
            return $unresolved(ResolutionStatus::NoBinding, 'No binding can exist for this request: ' . $e->getMessage());
        }
        $found = $this->bindings->matching($key);
        if ($found === []) {
            return $unresolved(ResolutionStatus::NoBinding, sprintf('No binding is registered for %s.', $key->bindingId()));
        }
        if (count($found) > 1) {
            return $unresolved(ResolutionStatus::Ambiguous, sprintf(
                'Binding %s is stored %d times, for fields %s; none is used until the duplicate is removed.',
                $key->bindingId(),
                count($found),
                implode(', ', array_map(static fn(MetricBinding $b): string => $b->field->toString(), $found)),
            ), $key->bindingId());
        }
        $binding = $found[0];
        if (!$binding->status->isUsable()) {
            return $unresolved(ResolutionStatus::NotApproved, sprintf('Binding %s is %s; only approved or verified bindings resolve.', $key->bindingId(), $binding->status->value), $key->bindingId());
        }
        $ownership = WriterOwnership::from($this->owners->ownership($binding->field)->value);

        return new BindingResolution(
            ResolutionStatus::Resolved,
            $deviceId,
            $metric,
            $resource,
            $protocol,
            sprintf('Binding %s is %s and its field is written by %s.', $key->bindingId(), $binding->status->value, $ownership->value),
            $key->bindingId(),
            $binding->packName,
            $binding->packVersion,
            $ownership,
            $binding->field->name->value,
            RrdDataSourceType::from($binding->expectedType->value),
            new StorageReference('local-data:' . $binding->field->localDataId),
        );
    }
}
