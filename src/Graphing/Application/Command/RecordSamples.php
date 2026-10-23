<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Graphing\Application\Command;

use Kadupul\Graphing\Application\Port\PlannedUpdate;
use Kadupul\Graphing\Application\Port\RrdTarget;
use Kadupul\Graphing\Application\Port\RrdTargetInspector;
use Kadupul\Graphing\Application\Port\RrdTargetLocator;
use Kadupul\Graphing\Application\Port\RrdUpdater;
use Kadupul\Graphing\Application\Port\StorageRejected;
use Kadupul\Graphing\Application\Port\UpdateEntry;
use Kadupul\Graphing\Application\Port\UpdateFormatter;
use Kadupul\Monitoring\Contract\BindingCatalog;
use Kadupul\Monitoring\Contract\BindingResolution;
use Kadupul\Monitoring\Contract\ResolutionStatus;
use Kadupul\Monitoring\Contract\StorageReference;
use Kadupul\Monitoring\Contract\WriterOwnership;
use Kadupul\Telemetry\Contract\InvalidTelemetryValue;
use Kadupul\Telemetry\Contract\RejectionReason;
use Kadupul\Telemetry\Contract\Sample;
use Kadupul\Telemetry\Contract\SampleOutcome;
use Kadupul\Telemetry\Contract\SampleSink;
use Kadupul\Telemetry\Contract\SinkReport;

/**
 * Stores samples in the RRD files their approved bindings name, and only
 * there. A sample is written only when the write gate is
 * open, its binding resolves for exactly that series, model-driven
 * collection owns the field, and the file holds the data source with the
 * expected type. Everything else is refused with a reason; nothing is
 * guessed and nothing is dropped.
 *
 * Each sample is resolved by its own device and collection protocol, so
 * one sink serves any number of devices and a batch may mix them.
 */
final class RecordSamples implements SampleSink
{
    public function __construct(
        private readonly BindingCatalog $bindings,
        private readonly WriteGate $gate,
        private readonly RrdTargetLocator $locator,
        private readonly RrdTargetInspector $inspector,
        private readonly UpdateFormatter $formatter,
        private readonly RrdUpdater $updater,
    ) {}

    public function accept(array $samples): SinkReport
    {
        $samples = array_values($samples);
        if (!$this->gate->permitsWrites()) {
            // Checked before resolution as well as storage, so a closed gate reads nothing at all.
            $closed = SampleOutcome::rejected(RejectionReason::WritesDisabled, 'Model-driven RRD writes are disabled; the sample was neither resolved nor stored.');

            return SinkReport::forOutcomes(array_fill(0, count($samples), $closed));
        }
        $outcomes = [];
        $references = [];
        $byFile = [];
        foreach ($samples as $position => $sample) {
            $resolution = $this->bindings->resolve($sample->deviceId, $sample->metric, $sample->resource, $sample->protocol->value);
            $refusal = $this->refusal($sample, $resolution);
            $storage = $resolution->storage;
            if ($refusal !== null || $storage === null) {
                $outcomes[$position] = $refusal ?? $this->rejected(RejectionReason::NoBinding, 'The binding resolved without a storage reference.');
                continue;
            }
            $key = $storage->toString();
            $references[$key] = $storage;
            $byFile[$key][] = [$position, $sample, $resolution];
        }
        foreach ($byFile as $key => $members) {
            // Each file is handled on its own, so one file's failure leaves the others' samples alone.
            $outcomes = $this->writeFile($references[$key], $members) + $outcomes;
        }
        ksort($outcomes);

        return SinkReport::forOutcomes(array_values($outcomes));
    }

    private function refusal(Sample $sample, BindingResolution $resolution): ?SampleOutcome
    {
        $metric = $sample->metric->toString();
        $device = $sample->deviceId;
        if ($resolution->status !== ResolutionStatus::Resolved) {
            return match ($resolution->status) {
                ResolutionStatus::Ambiguous => $this->rejected(RejectionReason::AmbiguousBinding, sprintf('More than one binding matches metric %s on device %d; none is used until the duplicate is removed.', $metric, $device)),
                ResolutionStatus::NotApproved => $this->rejected(RejectionReason::NoBinding, sprintf('The binding for metric %s on device %d is not approved.', $metric, $device)),
                default => $this->rejected(RejectionReason::NoBinding, sprintf('No binding is registered for metric %s on device %d.', $metric, $device)),
            };
        }
        if ($resolution->deviceId !== $device
            || !$resolution->metric->equals($sample->metric)
            || !$resolution->resource->equals($sample->resource)
            || $resolution->protocol !== $sample->protocol->value
        ) {
            return $this->rejected(RejectionReason::NoBinding, sprintf('The binding catalog answered for a different series than metric %s on device %d.', $metric, $device));
        }

        return match ($resolution->ownership) {
            WriterOwnership::ModelDriven => null,
            WriterOwnership::Conflict => $this->rejected(RejectionReason::OwnershipConflict, sprintf('Both the legacy poller and model-driven collection claim the field for metric %s; nothing is written until an operator resolves it.', $metric)),
            default => $this->rejected(RejectionReason::NotOwnedByModelDriven, sprintf('The field for metric %s is owned by %s, not by model-driven collection.', $metric, $resolution->ownership?->value ?? 'nobody')),
        };
    }

    /**
     * @param list<array{int, Sample, BindingResolution}> $members
     * @return array<int, SampleOutcome>
     */
    private function writeFile(StorageReference $reference, array $members): array
    {
        try {
            $target = $this->locator->locate($reference);
            $info = $this->inspector->inspect($target);
        } catch (StorageRejected $e) {
            return array_fill_keys(array_column($members, 0), $this->fromStorage($e));
        }
        $outcomes = [];
        $entries = [];
        foreach ($members as [$position, $sample, $resolution]) {
            $name = (string) $resolution->rrdDataSourceName;
            $expected = $resolution->rrdDataSourceType;
            $actual = $info->typeOf($name);
            if ($actual === null) {
                $outcomes[$position] = $this->rejected(RejectionReason::TargetMissing, sprintf('The file for %s has no data source named %s.', $reference->toString(), $name));
            } elseif ($expected === null || $actual !== $expected->value) {
                $outcomes[$position] = $this->rejected(RejectionReason::TypeMismatch, sprintf('Data source %s is %s in the file, but the binding expects %s.', $name, $actual, $expected?->value ?? 'nothing'));
            } else {
                $entries[] = new UpdateEntry($position, $sample, $name, $expected);
            }
        }
        $plan = $this->formatter->format($entries, $info->lastUpdate);
        $outcomes = $plan->rejections + $outcomes;
        foreach ($plan->updates as $index => $update) {
            try {
                $this->updater->update($target, $update->command);
            } catch (StorageRejected $e) {
                return $this->afterFailedCall($target, $e, array_slice($plan->updates, $index)) + $outcomes;
            }
            foreach ($this->positions($update) as $position) {
                $outcomes[$position] = SampleOutcome::accepted();
            }
        }

        return $outcomes;
    }

    /**
     * RRDtool keeps the rows it applied before the failing one, so the file
     * is read again instead of reporting them as failed. Later calls to the
     * same file are not attempted: their rows were planned on the assumption
     * that this one succeeded.
     *
     * @param non-empty-list<PlannedUpdate> $remaining the failed call first
     * @return array<int, SampleOutcome>
     */
    private function afterFailedCall(RrdTarget $target, StorageRejected $failure, array $remaining): array
    {
        try {
            $stored = $this->inspector->inspect($target)->lastUpdate;
        } catch (StorageRejected) {
            // Unknown is reported as failed; claiming a write that may not exist would be worse.
            $stored = null;
        }
        $failed = $this->fromStorage($failure);
        $skipped = SampleOutcome::rejected(RejectionReason::StorageFailure, 'Not attempted because an earlier call to the same file failed.');
        $outcomes = [];
        foreach ($remaining as $index => $update) {
            foreach ($update->positionsByTimestamp as $second => $positions) {
                foreach ($positions as $position) {
                    $outcomes[$position] = match (true) {
                        $index > 0 => $skipped,
                        $stored !== null && $second <= $stored => SampleOutcome::accepted(),
                        default => $failed,
                    };
                }
            }
        }

        return $outcomes;
    }

    /** @return list<int> */
    private function positions(PlannedUpdate $update): array
    {
        return array_merge(...array_values($update->positionsByTimestamp));
    }

    private function fromStorage(StorageRejected $e): SampleOutcome
    {
        return $this->rejected($e->reason, $e->getMessage());
    }

    /**
     * Details reach logs and API responses. Text built from binding data or
     * an adapter message that SampleOutcome refuses, for instance because it
     * holds a path, is replaced rather than allowed to fail the whole batch.
     */
    private function rejected(RejectionReason $reason, string $detail): SampleOutcome
    {
        try {
            return SampleOutcome::rejected($reason, $detail);
        } catch (InvalidTelemetryValue) {
            return SampleOutcome::rejected($reason, 'The detail for this rejection was withheld because it was not safe to publish.');
        }
    }
}
