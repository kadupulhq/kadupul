<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Monitoring\Domain;

use Kadupul\Telemetry\Contract\CollectionError;
use Kadupul\Telemetry\Contract\CollectionStatus;

final readonly class CapabilityDiscoveryResult
{
    /** @var list<Capability> */
    public array $capabilities;

    /**
     * @param list<Capability> $capabilities ordered by key so equal discoveries compare equal
     * @param list<CollectionError> $errors
     * @param list<Capability> $notReported verified outcomes a provider stopped reporting; kept, not dropped
     */
    public function __construct(
        public int $deviceId,
        array $capabilities,
        public array $errors = [],
        public array $notReported = [],
    ) {
        if ($deviceId < 1) {
            throw new InvalidCapability('A device id must be a positive integer.');
        }
        $byKey = [];
        foreach ($capabilities as $capability) {
            if (!$capability instanceof Capability) {
                throw new InvalidCapability('A discovery result holds Capability values only.');
            }
            if (isset($byKey[$capability->key()])) {
                throw new InvalidCapability(sprintf('Model %s is reported twice over %s.', $capability->model->key(), $capability->source));
            }
            $byKey[$capability->key()] = $capability;
        }
        ksort($byKey, SORT_STRING);
        $this->capabilities = array_values($byKey);
    }

    public function status(): CollectionStatus
    {
        if ($this->errors === []) {
            return CollectionStatus::Succeeded;
        }

        return $this->capabilities === [] ? CollectionStatus::Failed : CollectionStatus::Partial;
    }
}
