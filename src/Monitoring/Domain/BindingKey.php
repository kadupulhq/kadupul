<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Monitoring\Domain;

use Kadupul\Telemetry\Contract\MetricId;
use Kadupul\Telemetry\Contract\ResourceId;

/** What a binding is for: at most one binding exists per key. */
final readonly class BindingKey
{
    public function __construct(
        public int $deviceId,
        public MetricId $metric,
        public ResourceId $resource,
        public string $protocol,
    ) {
        if ($deviceId < 1) {
            throw new InvalidBinding('A binding needs a positive device id.');
        }
        if (!in_array($protocol, Capability::PROTOCOLS, true)) {
            throw new InvalidBinding(sprintf('Unknown binding protocol "%s"; expected one of %s.', $protocol, implode(', ', Capability::PROTOCOLS)));
        }
    }

    public function equals(self $other): bool
    {
        return $this->canonical() === $other->canonical();
    }

    /**
     * The opaque id a collection plan carries instead of any storage detail.
     * It is derived from the key alone, so it survives a change of storage.
     */
    public function bindingId(): string
    {
        return 'bnd-' . substr(hash('sha256', $this->canonical()), 0, 32);
    }

    public function canonical(): string
    {
        // JSON keeps an identity containing a separator from colliding with another.
        return json_encode([$this->deviceId, $this->metric->toString(), $this->resource->kind, $this->resource->identity, $this->protocol], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
