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

/** Keyed by binding key, so saving the same key replaces the earlier version. */
final class InMemoryBindingRepository implements BindingRepository
{
    /** @var array<string, MetricBinding> */
    private array $bindings = [];

    public function matching(BindingKey $key): array
    {
        return isset($this->bindings[$key->canonical()]) ? [$this->bindings[$key->canonical()]] : [];
    }

    public function writingTo(StorageField $field): array
    {
        return array_values(array_filter($this->bindings, static fn(MetricBinding $b): bool => $b->field->equals($field)));
    }

    public function forDevice(int $deviceId): array
    {
        return array_values(array_filter($this->bindings, static fn(MetricBinding $b): bool => $b->key->deviceId === $deviceId));
    }

    public function save(MetricBinding $binding): void
    {
        $this->bindings[$binding->key->canonical()] = $binding;
    }
}
