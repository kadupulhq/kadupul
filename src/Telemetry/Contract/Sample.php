<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Telemetry\Contract;

final readonly class Sample
{
    public \DateTimeImmutable $timestamp;

    /** @var array<string, string> */
    public array $labels;

    /**
     * @param int $deviceId the device the reading was collected from
     * @param CollectionProtocol $protocol the protocol it was collected over; storage bindings are selected by it
     * @param array<string, string> $labels
     * @param string $source free provenance text, such as the collector that produced the reading; nothing interprets it
     * @param bool $deviceRestarted the source reported an uptime decrease or restart since the previous reading
     */
    public function __construct(
        public int $deviceId,
        public CollectionProtocol $protocol,
        public MetricId $metric,
        public ResourceId $resource,
        public MetricType $type,
        public Unit $unit,
        \DateTimeImmutable $timestamp,
        public SampleValue $value,
        array $labels = [],
        public string $source = 'unknown',
        public DataQuality $quality = DataQuality::Good,
        public ?CounterWidth $counterWidth = null,
        public bool $deviceRestarted = false,
    ) {
        if ($deviceId < 1) {
            throw new InvalidTelemetryValue('A sample needs the positive id of the device it was collected from; got ' . $deviceId . '.');
        }
        if (preg_match('/\A[a-z][a-z0-9_-]*\z/', $source) !== 1) {
            throw new InvalidTelemetryValue('A sample source must be a lowercase token such as snmp or poller-1.');
        }
        if ($type === MetricType::Counter && !$value instanceof CounterValue && !$value instanceof UnknownValue) {
            throw new InvalidTelemetryValue('A counter metric must carry a counter value or an unknown reading.');
        }
        if ($counterWidth !== null) {
            if (!$value instanceof CounterValue) {
                throw new InvalidTelemetryValue('A counter width applies only to counter values.');
            }
            if (!$value->fitsWidth($counterWidth)) {
                throw new InvalidTelemetryValue('The value ' . $value->toString() . ' does not fit a ' . $counterWidth->value . '-bit counter.');
            }
        }
        foreach ($labels as $key => $label) {
            if ((string) $key === '' || !is_string($label)) {
                throw new InvalidTelemetryValue('Labels must map non-empty names to string values.');
            }
        }
        ksort($labels, SORT_STRING);
        $this->labels = $labels;
        // Same instant, UTC, microseconds untouched: setTimezone never rounds.
        $this->timestamp = $timestamp->setTimezone(new \DateTimeZone('UTC'));
    }

    /**
     * Identifies the logical time series: one metric on one resource of one
     * device, whatever protocol produced the reading.
     */
    public function seriesKey(): string
    {
        return $this->deviceId . "\n" . $this->metric->toString() . "\n" . $this->resource->toString() . "\n" . json_encode($this->labels, JSON_THROW_ON_ERROR);
    }
}
