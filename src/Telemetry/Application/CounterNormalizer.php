<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Telemetry\Application;

use Kadupul\Telemetry\Application\Contract\Clock;
use Kadupul\Telemetry\Contract\CollectionError;
use Kadupul\Telemetry\Contract\CollectionErrorCode;
use Kadupul\Telemetry\Contract\CounterValue;
use Kadupul\Telemetry\Contract\CounterWidth;
use Kadupul\Telemetry\Contract\DataQuality;
use Kadupul\Telemetry\Contract\MetricType;
use Kadupul\Telemetry\Contract\Sample;
use Kadupul\Telemetry\Domain\CardinalityGuard;

/**
 * Turns the raw readings of one counter series into intervals a rate can be
 * computed from, whatever protocol produced them.
 */
final class CounterNormalizer
{
    public function __construct(private readonly Clock $clock, private readonly CardinalityGuard $cardinality) {}

    /**
     * @param list<mixed> $samples readings of a single series, in any arrival order
     */
    public function normalize(array $samples, CounterNormalizationPolicy $policy): NormalizationReport
    {
        if ($samples === []) {
            return new NormalizationReport([], [], 0);
        }
        $first = $samples[array_key_first($samples)];
        foreach ($samples as $sample) {
            if (!$sample instanceof Sample) {
                throw new UnusableSeries('Only Sample values can be normalized.');
            }
            if ($sample->type !== MetricType::Counter) {
                throw new UnusableSeries('Only counter metrics can be normalized; ' . $sample->metric->toString() . ' is a ' . $sample->type->value . '.');
            }
            if (!$sample->value instanceof CounterValue) {
                throw new UnusableSeries('Only counter values can be normalized; ' . $sample->metric->toString() . ' has a reading of ' . $sample->value::class . '.');
            }
            // The protocol is part of a counter's identity here: two protocols read
            // two counters, and a delta between their baselines is not traffic.
            if ($sample->seriesKey() !== $first->seriesKey() || $sample->protocol !== $first->protocol) {
                throw new UnusableSeries('All samples must belong to one series; got ' . self::describe($first) . ' and ' . self::describe($sample) . '.');
            }
        }
        $this->cardinality->admit($first->metric->toString(), $first->protocol->value . "\n" . $first->seriesKey());

        [$series, $errors, $duplicates] = $this->deduplicate($samples);
        // Sorting rather than rejecting: gNMI streams and retried polls deliver
        // late readings, and ordering by instant gives one answer for any arrival order.
        usort($series, static fn(Sample $a, Sample $b): int => [$a->timestamp->getTimestamp(), (int) $a->timestamp->format('u')] <=> [$b->timestamp->getTimestamp(), (int) $b->timestamp->format('u')]);

        $intervals = [];
        for ($i = 1; $i < count($series); $i++) {
            $intervals[] = $this->interval($series[$i - 1], $series[$i], $policy);
        }

        return new NormalizationReport($intervals, $errors, $duplicates);
    }

    /**
     * @param list<Sample> $samples
     * @return array{list<Sample>, list<CollectionError>, int}
     */
    private function deduplicate(array $samples): array
    {
        $byInstant = [];
        foreach ($samples as $sample) {
            $byInstant[$sample->timestamp->format('U.u')][] = $sample;
        }
        $kept = [];
        $errors = [];
        $duplicates = 0;
        foreach ($byInstant as $group) {
            $values = array_unique(array_map(static fn(Sample $s): string => $s->value->toWire(), $group));
            if (count($values) > 1) {
                $errors[] = new CollectionError(
                    CollectionErrorCode::Conflict,
                    'Readings at ' . $group[0]->timestamp->format('Y-m-d\TH:i:s.u\Z') . ' report different values (' . implode(', ', $values) . '); none of them was used.',
                    $group[0]->timestamp,
                );
                continue;
            }
            $duplicates += count($group) - 1;
            // A restart flag on any copy must survive de-duplication.
            $restarted = array_values(array_filter($group, static fn(Sample $s): bool => $s->deviceRestarted));
            $kept[] = $restarted[0] ?? $group[0];
        }

        return [$kept, $errors, $duplicates];
    }

    private function interval(Sample $from, Sample $to, CounterNormalizationPolicy $policy): CounterInterval
    {
        $elapsed = $this->microsBetween($from->timestamp, $to->timestamp);
        $quality = $from->quality->worst($to->quality);
        if ($this->microsBetween($to->timestamp, $this->clock->now()) > $policy->staleAfterSeconds * 1_000_000) {
            $quality = $quality->worst(DataQuality::Stale);
        }
        if ($to->deviceRestarted) {
            return new CounterInterval(IntervalKind::Reset, $from->timestamp, $to->timestamp, $elapsed, null, $quality);
        }
        if ($elapsed > $policy->maxGapSeconds * 1_000_000) {
            return new CounterInterval(IntervalKind::Gap, $from->timestamp, $to->timestamp, $elapsed, null, DataQuality::Bad);
        }
        // normalize() has already rejected every other value type.
        assert($from->value instanceof CounterValue && $to->value instanceof CounterValue);
        if ($to->value->compare($from->value) >= 0) {
            return new CounterInterval(IntervalKind::Delta, $from->timestamp, $to->timestamp, $elapsed, $to->value->minus($from->value), $quality);
        }
        // Only a 32-bit counter can wrap within a polling interval. A 64-bit
        // counter takes decades to wrap at line rate, so a decrease there, or
        // on a counter of unknown width, is a reset (ADR 0004).
        $width = $to->counterWidth ?? $from->counterWidth ?? $policy->widthFor($to->metric->toString());
        if ($width === CounterWidth::Bits32 && $from->value->fitsWidth($width) && $to->value->fitsWidth($width)) {
            return new CounterInterval(IntervalKind::Delta, $from->timestamp, $to->timestamp, $elapsed, $to->value->wrappedDelta($from->value, $width), $quality, true);
        }

        return new CounterInterval(IntervalKind::Reset, $from->timestamp, $to->timestamp, $elapsed, null, $quality);
    }

    private static function describe(Sample $sample): string
    {
        return sprintf('%s %s on device %d over %s', $sample->metric->toString(), $sample->resource->toString(), $sample->deviceId, $sample->protocol->value);
    }

    private function microsBetween(\DateTimeImmutable $earlier, \DateTimeImmutable $later): int
    {
        return ($later->getTimestamp() - $earlier->getTimestamp()) * 1_000_000 + ((int) $later->format('u') - (int) $earlier->format('u'));
    }
}
