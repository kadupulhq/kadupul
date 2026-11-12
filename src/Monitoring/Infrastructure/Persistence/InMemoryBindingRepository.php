<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Monitoring\Infrastructure\Persistence;

use Kadupul\Monitoring\Application\Port\BindingRepository;
use Kadupul\Monitoring\Domain\BindingKey;
use Kadupul\Monitoring\Domain\MetricBinding;
use Kadupul\Monitoring\Domain\StorageField;

/**
 * Keyed by binding key, so saving the same key replaces the earlier version,
 * subject to the same replacement rule the database store applies.
 */
final class InMemoryBindingRepository implements BindingRepository
{
    /** @var array<string, MetricBinding> */
    private array $bindings = [];

    /** Bindings stored under the key; at most one. */
    public function matching(BindingKey $key): array
    {
        return isset($this->bindings[$key->canonical()]) ? [$this->bindings[$key->canonical()]] : [];
    }

    /** Bindings that point at the storage field. */
    public function writingTo(StorageField $field): array
    {
        return array_values(array_filter($this->bindings, static fn(MetricBinding $b): bool => $b->field->equals($field)));
    }

    /** Bindings of one device. */
    public function forDevice(int $deviceId): array
    {
        return array_values(array_filter($this->bindings, static fn(MetricBinding $b): bool => $b->key->deviceId === $deviceId));
    }

    /**
     * Stores the binding under its key.
     *
     * @throws \Kadupul\Monitoring\Domain\BindingConflict when the stored binding cannot be replaced
     * @throws \Kadupul\Monitoring\Domain\BindingTransitionRejected when the status change is not allowed from the stored state
     */
    public function save(MetricBinding $binding): void
    {
        $stored = $this->bindings[$binding->key->canonical()] ?? null;
        if ($stored !== null) {
            $binding->assertMayReplace($stored);
        }
        $this->bindings[$binding->key->canonical()] = $binding;
    }
}
