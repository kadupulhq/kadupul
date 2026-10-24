<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Monitoring\Application\Port;

use Kadupul\Monitoring\Domain\BindingKey;
use Kadupul\Monitoring\Domain\MetricBinding;
use Kadupul\Monitoring\Domain\StorageField;

/**
 * Stored metric bindings. Lookups return lists rather than one binding so a
 * store without a uniqueness guarantee shows a duplicate instead of hiding
 * it; callers treat more than one result as ambiguous.
 */
interface BindingRepository
{
    /** @return list<MetricBinding> */
    public function matching(BindingKey $key): array;

    /** @return list<MetricBinding> */
    public function writingTo(StorageField $field): array;

    /** @return list<MetricBinding> */
    public function forDevice(int $deviceId): array;

    public function save(MetricBinding $binding): void;
}
