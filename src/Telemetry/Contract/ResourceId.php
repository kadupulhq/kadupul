<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Telemetry\Contract;

/**
 * Identifies the thing a metric measures on a device, such as an interface, by kind and identity.
 */
final readonly class ResourceId
{
    /**
     * Validates the kind and identity.
     *
     * @throws InvalidTelemetryValue when the kind is not a lowercase token or the identity is empty or has control characters
     */
    public function __construct(public string $kind, public string $identity)
    {
        if (preg_match('/\A[a-z][a-z0-9_]*\z/', $kind) !== 1) {
            throw new InvalidTelemetryValue('A resource kind must be a lowercase token such as interface.');
        }
        if ($identity === '' || preg_match('/[\x00-\x1f\x7f]/', $identity) === 1) {
            throw new InvalidTelemetryValue('A resource identity must be non-empty text without control characters.');
        }
    }

    /**
     * Returns the id as kind/identity.
     */
    public function toString(): string
    {
        return $this->kind . '/' . $this->identity;
    }

    /**
     * Tells whether both ids have the same kind and identity.
     */
    public function equals(self $other): bool
    {
        return $this->kind === $other->kind && $this->identity === $other->identity;
    }
}
