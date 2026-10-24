<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Monitoring\Application;

use Kadupul\Monitoring\Application\Port\BindingRepository;
use Kadupul\Monitoring\Domain\BindingConflict;
use Kadupul\Monitoring\Domain\BindingKey;
use Kadupul\Monitoring\Domain\BindingMatch;
use Kadupul\Monitoring\Domain\BindingOrigin;
use Kadupul\Monitoring\Domain\BindingProvenance;
use Kadupul\Monitoring\Domain\BindingStatus;
use Kadupul\Monitoring\Domain\InvalidBinding;
use Kadupul\Monitoring\Domain\MetricBinding;

/**
 * Records a proposed binding. Repeating an identical registration returns
 * the stored binding unchanged, so a retried discovery run is harmless. A
 * different definition for a known key, or a second key for a storage field
 * already bound, is rejected rather than replacing what an operator may have
 * approved.
 */
final class RegisterBinding
{
    public function __construct(private readonly BindingRepository $bindings) {}

    public function register(MetricBinding $binding): MetricBinding
    {
        if ($binding->status !== BindingStatus::Proposed) {
            throw new InvalidBinding(sprintf('Binding %s must be registered as proposed, not %s; approval is a separate step.', $binding->key->bindingId(), $binding->status->value));
        }
        foreach ($this->bindings->matching($binding->key) as $existing) {
            // Returning an identical rejected binding keeps rediscovery from reviving what an operator turned down.
            if ($existing->status === BindingStatus::Rejected && !$existing->definitionEquals($binding)) {
                continue;
            }
            if (!$existing->definitionEquals($binding)) {
                throw new BindingConflict(sprintf(
                    'Binding %s already writes %s as %s for pack %s %s; reject it before registering a different definition.',
                    $binding->key->bindingId(),
                    $existing->field->toString(),
                    $existing->expectedType->value,
                    $existing->packName,
                    $existing->packVersion,
                ));
            }

            return $existing;
        }
        foreach ($this->bindings->writingTo($binding->field) as $other) {
            if ($other->status !== BindingStatus::Rejected) {
                throw new BindingConflict(sprintf('Storage field %s is already bound by %s; one field holds one series.', $binding->field->toString(), $other->key->bindingId()));
            }
        }
        $this->bindings->save($binding);

        return $binding;
    }

    /** Proposes a binding found in legacy data sources. Only a confirmed match qualifies. */
    public function discovered(BindingKey $key, string $packName, string $packVersion, BindingMatch $match, \DateTimeImmutable $at): MetricBinding
    {
        if (!$match->canBind() || $match->field === null || $match->type === null) {
            throw new InvalidBinding(sprintf('A %s legacy match for %s cannot become a binding: %s.', $match->confidence->value, $key->bindingId(), implode('; ', $match->reasons)));
        }

        return $this->register(new MetricBinding(
            $key,
            $packName,
            $packVersion,
            $match->field,
            $match->type,
            BindingStatus::Proposed,
            new BindingProvenance(BindingOrigin::LegacyDiscovered, implode('; ', $match->reasons), $at),
            null,
        ));
    }
}
