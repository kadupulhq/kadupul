<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Monitoring\Application;

use Kadupul\ModelRegistry\Contract\ModelCatalog;
use Kadupul\Monitoring\Application\Port\CapabilityProvider;
use Kadupul\Monitoring\Application\Port\DeviceCapabilityStore;
use Kadupul\Monitoring\Domain\Capability;
use Kadupul\Monitoring\Domain\CapabilityDiscoveryResult;
use Kadupul\Monitoring\Domain\CapabilityStatus;
use Kadupul\Telemetry\Contract\CollectionError;
use Kadupul\Telemetry\Contract\CollectionErrorCode;

/**
 * Asks every provider what a device supports, merges the answers into the
 * stored state without weakening earlier verification, and assesses each
 * model against the registry. It only reads from devices and the registry;
 * the capability store is the one thing it writes.
 */
final class DiscoverDeviceCapabilities
{
    /** @var list<CapabilityProvider> */
    private array $providers;

    /**
     * @param iterable<CapabilityProvider> $providers
     */
    public function __construct(
        iterable $providers,
        private readonly DeviceCapabilityStore $store,
        private readonly ModelCatalog $catalog,
    ) {
        $this->providers = [];
        foreach ($providers as $provider) {
            $this->providers[] = $provider;
        }
    }

    public function __invoke(int $deviceId): CapabilityDiscoveryResult
    {
        $stored = [];
        foreach ($this->store->capabilitiesFor($deviceId) as $capability) {
            $stored[$capability->key()] = $capability;
        }
        $errors = [];
        $observed = [];
        $answeredInFull = [];
        foreach ($this->providers as $provider) {
            $protocol = $provider->protocol();
            try {
                $answer = $provider->discoverCapabilities($deviceId);
            } catch (\Exception $e) {
                // \Error is not caught: a programming fault must surface, not
                // be filed as an unreachable device.
                $errors[] = new CollectionError(CollectionErrorCode::Unavailable, sprintf('Capability discovery over %s failed for device %d: %s', $protocol, $deviceId, $e->getMessage()));
                continue;
            }
            if ($answer->deviceId !== $deviceId) {
                $errors[] = new CollectionError(CollectionErrorCode::Conflict, sprintf('The %s capability provider answered for device %d when asked about device %d; its answer was not used.', $protocol, $answer->deviceId, $deviceId));
                continue;
            }
            $clean = $answer->errors === [];
            array_push($errors, ...$answer->errors);
            foreach ($answer->capabilities as $capability) {
                if ($capability->source !== $protocol) {
                    $errors[] = new CollectionError(CollectionErrorCode::Conflict, sprintf('The %s capability provider reported %s as a %s capability; it was not used.', $protocol, $capability->model->key(), $capability->source));
                    $clean = false;
                    continue;
                }
                $observed[$capability->key()] = $capability;
            }
            if ($clean) {
                $answeredInFull[$protocol] = true;
            }
        }

        $merged = [];
        $notReported = [];
        foreach ($observed as $key => $capability) {
            $merged[$key] = isset($stored[$key]) ? $this->merge($stored[$key], $capability) : $capability;
        }
        foreach ($stored as $key => $previous) {
            if (isset($merged[$key])) {
                continue;
            }
            if (!isset($answeredInFull[$previous->source])) {
                // The provider failed or was not asked; what it said before still stands.
                $merged[$key] = $previous;
            } elseif ($previous->status->isVerificationOutcome()) {
                $merged[$key] = $previous;
                $notReported[] = $previous;
            } else {
                $merged[$key] = new Capability($previous->model, $previous->source, CapabilityStatus::Unknown, sprintf('No longer advertised over %s.', $previous->source));
            }
        }
        ksort($merged, SORT_STRING);
        $merged = array_values($merged);
        $this->store->replace($deviceId, $merged);

        return new CapabilityDiscoveryResult(
            $deviceId,
            array_map(fn(Capability $c): Capability => $this->assess($c, $merged), $merged),
            $errors,
            $notReported,
        );
    }

    private function merge(Capability $stored, Capability $observed): Capability
    {
        if ($observed->status->isVerificationOutcome() || !$stored->status->isVerificationOutcome()) {
            return $observed;
        }

        return $stored;
    }

    /**
     * @param list<Capability> $device everything the device reported, for dependency checks
     */
    private function assess(Capability $capability, array $device): Capability
    {
        $model = $capability->model;
        $summary = $this->catalog->find($model->family, $model->namespace, $model->name, $model->revision);
        if ($summary === null) {
            if ($capability->status === CapabilityStatus::Unsupported) {
                return $capability;
            }

            return $capability->assessed(CapabilityStatus::Unknown, sprintf('Model %s revision %s is not registered in the model registry.', $model->identityKey(), $model->revision), []);
        }
        if (!in_array($capability->status, [CapabilityStatus::Advertised, CapabilityStatus::Verified, CapabilityStatus::Partial], true)) {
            return $capability->assessed($capability->status, $capability->reason, $summary->capabilities);
        }

        $missing = [];
        $tooOld = [];
        foreach ($summary->dependencies as $dependency) {
            [$identity, $minimum] = array_pad(explode('@', $dependency, 2), 2, null);
            $present = [];
            foreach ($device as $other) {
                if ($other->model->identityKey() === $identity && in_array($other->status, [CapabilityStatus::Advertised, CapabilityStatus::Verified, CapabilityStatus::Partial], true)) {
                    $present[] = $other->model->revision;
                }
            }
            if ($present === []) {
                $missing[] = $identity;
            } elseif ($minimum !== null && !$this->anyAtLeast($identity, $present, $minimum)) {
                $tooOld[] = sprintf('%s at revision %s or newer, but the device advertises %s', $identity, $minimum, implode(', ', array_unique($present)));
            }
        }
        if ($tooOld !== []) {
            return $capability->assessed(CapabilityStatus::Unsupported, 'Requires ' . implode('; ', $tooOld) . '.', $summary->capabilities);
        }
        if ($missing !== []) {
            return $capability->assessed(CapabilityStatus::Partial, 'Depends on ' . implode(', ', $missing) . ', which the device does not advertise.', $summary->capabilities);
        }

        return $capability->assessed($capability->status, $capability->reason, $summary->capabilities);
    }

    /** @param list<string> $revisions */
    private function anyAtLeast(string $identity, array $revisions, string $minimum): bool
    {
        // Date revisions (YANG, OpenConfig) order as strings; others are version numbers.
        $dated = str_starts_with($identity, 'yang|') || str_starts_with($identity, 'openconfig|');
        foreach ($revisions as $revision) {
            if ($dated ? strcmp($revision, $minimum) >= 0 : version_compare($revision, $minimum, '>=')) {
                return true;
            }
        }

        return false;
    }
}
