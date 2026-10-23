<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Graphing\Infrastructure\Rrd;

use Kadupul\Graphing\Application\Port\Clock;
use Kadupul\Graphing\Application\Port\PlannedUpdate;
use Kadupul\Graphing\Application\Port\UpdateEntry;
use Kadupul\Graphing\Application\Port\UpdateFormatter;
use Kadupul\Graphing\Application\Port\UpdatePlan;
use Kadupul\Graphing\Domain\RrdCommand;
use Kadupul\Monitoring\Contract\RrdDataSourceType;
use Kadupul\Telemetry\Contract\AbsoluteValue;
use Kadupul\Telemetry\Contract\CounterValue;
use Kadupul\Telemetry\Contract\DeriveValue;
use Kadupul\Telemetry\Contract\GaugeValue;
use Kadupul\Telemetry\Contract\RejectionReason;
use Kadupul\Telemetry\Contract\SampleOutcome;

/**
 * Builds rrdtool update arguments for one file: `--template a:b` followed by
 * `<epoch>:<a>:<b>` rows, each a separate argument so no shell or pipe
 * quoting is involved. Values are written exactly as SampleValue::toWire()
 * gives them, so 64-bit counters never pass through a float, and an unknown
 * reading is U.
 *
 * Raw counter readings are written to COUNTER, DERIVE and ABSOLUTE data
 * sources unchanged. Rate calculation, rollover and reset handling stay with
 * RRDtool, which applies them according to the data-source type preserved in
 * the file; Telemetry's CounterInterval is not used for storage.
 *
 * This class does no I/O. The caller supplies the file's last update time,
 * read from the file, so samples RRDtool would refuse are refused here with
 * a reason instead of failing the whole call.
 */
final class RrdUpdateFormatter implements UpdateFormatter
{
    private const UNSIGNED_MAX = '18446744073709551615';
    private const SIGNED_MIN = '-9223372036854775808';
    private const SIGNED_MAX = '9223372036854775807';
    private const GAUGE_MAX_LENGTH = 64;

    private const FAMILIES = [CounterValue::class, AbsoluteValue::class, DeriveValue::class, GaugeValue::class];

    /** @param int $maxFutureSeconds how far past the clock a sample may be, to absorb device clock skew */
    public function __construct(
        private readonly Clock $clock,
        private readonly int $maxFutureSeconds = 300,
    ) {
        if ($maxFutureSeconds < 0) {
            throw new \InvalidArgumentException('The allowed future skew must not be negative.');
        }
    }

    public function format(array $entries, ?int $lastUpdate): UpdatePlan
    {
        $latestAllowed = $this->clock->now()->getTimestamp() + $this->maxFutureSeconds;
        $rejections = [];
        /** @var array<int, array<string, array{string, int}>> $rows */
        $rows = [];
        foreach ($entries as $entry) {
            $refusal = $this->refusal($entry, $latestAllowed);
            if ($refusal !== null) {
                $rejections[$entry->position] = $refusal;
                continue;
            }
            $second = $entry->sample->timestamp->getTimestamp();
            if ($lastUpdate !== null && $second <= $lastUpdate) {
                $rejections[$entry->position] = $second === $lastUpdate
                    ? SampleOutcome::rejected(RejectionReason::DuplicateSample, sprintf('The file already holds a row for second %d.', $second))
                    : SampleOutcome::rejected(RejectionReason::OutOfOrder, sprintf('Second %d is older than the newest row in the file, second %d.', $second, $lastUpdate));
                continue;
            }
            if (isset($rows[$second][$entry->dataSourceName])) {
                // Rows have one-second resolution, so two readings within one second cannot both be stored.
                $rejections[$entry->position] = SampleOutcome::rejected(
                    RejectionReason::DuplicateSample,
                    sprintf('An earlier sample in this batch already fills data source %s at second %d.', $entry->dataSourceName, $second),
                );
                continue;
            }
            $rows[$second][$entry->dataSourceName] = [$entry->sample->value->toWire(), $entry->position];
        }

        return new UpdatePlan($rejections, $this->updates($rows));
    }

    /**
     * Each call must be newer than the one before it, so rows are taken in
     * time order and a change of template starts a new call; grouping all
     * rows of one template together would reorder them.
     *
     * @param array<int, array<string, array{string, int}>> $rows
     * @return list<PlannedUpdate>
     */
    private function updates(array $rows): array
    {
        ksort($rows, SORT_NUMERIC);
        $updates = [];
        $template = null;
        $lines = [];
        $positions = [];
        foreach ($rows as $second => $fields) {
            ksort($fields, SORT_STRING);
            $names = implode(':', array_map('strval', array_keys($fields)));
            if ($template !== null && $names !== $template) {
                $updates[] = new PlannedUpdate(new RrdCommand('update', ['--template', $template, ...$lines]), $positions);
                $lines = [];
                $positions = [];
            }
            $template = $names;
            $lines[] = $second . ':' . implode(':', array_column($fields, 0));
            $rowPositions = array_column($fields, 1);
            sort($rowPositions);
            $positions[$second] = $rowPositions;
        }
        if ($template !== null) {
            $updates[] = new PlannedUpdate(new RrdCommand('update', ['--template', $template, ...$lines]), $positions);
        }

        return $updates;
    }

    private function refusal(UpdateEntry $entry, int $latestAllowed): ?SampleOutcome
    {
        // A leading hyphen is legal in RRDtool but would read as an option if it ever led an argument.
        if (preg_match('/\A[A-Za-z0-9_][A-Za-z0-9_-]{0,18}\z/', $entry->dataSourceName) !== 1) {
            return SampleOutcome::rejected(RejectionReason::TargetMissing, 'The bound data-source name is not 1 to 19 characters of letters, digits, underscore or hyphen, so no RRD file can hold it.');
        }
        $second = $entry->sample->timestamp->getTimestamp();
        if ($second <= 0) {
            return SampleOutcome::rejected(RejectionReason::InvalidTimestamp, sprintf('Timestamp %d is not after the Unix epoch.', $second));
        }
        if ($second > $latestAllowed) {
            return SampleOutcome::rejected(RejectionReason::InvalidTimestamp, sprintf('Timestamp %d is more than %d seconds ahead of the collector clock.', $second, $this->maxFutureSeconds));
        }
        $value = $entry->sample->value;
        if (in_array($value::class, self::FAMILIES, true) && !in_array($value::class, $this->families($entry->type), true)) {
            return SampleOutcome::rejected(RejectionReason::TypeMismatch, sprintf('A %s reading cannot be stored in a %s data source.', $this->familyName($value::class), $entry->type->value));
        }
        if (!$this->isWireValue($value->toWire(), $entry->type)) {
            return SampleOutcome::rejected(RejectionReason::InvalidValue, sprintf('The reading is not a valid %s value; expected %s or U.', $entry->type->value, $this->expectation($entry->type)));
        }

        return null;
    }

    /** @return list<class-string> */
    private function families(RrdDataSourceType $type): array
    {
        return match ($type) {
            RrdDataSourceType::Counter, RrdDataSourceType::Absolute => [CounterValue::class, AbsoluteValue::class],
            RrdDataSourceType::Derive => [DeriveValue::class],
            RrdDataSourceType::Gauge => [GaugeValue::class],
        };
    }

    private function familyName(string $class): string
    {
        return match ($class) {
            CounterValue::class => 'counter',
            AbsoluteValue::class => 'absolute',
            DeriveValue::class => 'derive',
            default => 'gauge',
        };
    }

    /**
     * SampleValue is an open interface, so the text is checked again here:
     * a separator, whitespace, NUL or exponent would change or break the row.
     */
    private function isWireValue(string $wire, RrdDataSourceType $type): bool
    {
        if ($wire === 'U') {
            return true;
        }

        return match ($type) {
            RrdDataSourceType::Counter, RrdDataSourceType::Absolute => preg_match('/\A[0-9]{1,20}\z/', $wire) === 1
                && gmp_cmp(gmp_init($wire, 10), self::UNSIGNED_MAX) <= 0,
            RrdDataSourceType::Derive => preg_match('/\A-?[0-9]{1,20}\z/', $wire) === 1
                && gmp_cmp(gmp_init($wire, 10), self::SIGNED_MIN) >= 0
                && gmp_cmp(gmp_init($wire, 10), self::SIGNED_MAX) <= 0,
            RrdDataSourceType::Gauge => strlen($wire) <= self::GAUGE_MAX_LENGTH
                && preg_match('/\A-?[0-9]+(?:\.[0-9]+)?\z/', $wire) === 1,
        };
    }

    private function expectation(RrdDataSourceType $type): string
    {
        return match ($type) {
            RrdDataSourceType::Counter, RrdDataSourceType::Absolute => 'an unsigned 64-bit integer',
            RrdDataSourceType::Derive => 'a signed 64-bit integer',
            RrdDataSourceType::Gauge => 'a plain decimal number of at most ' . self::GAUGE_MAX_LENGTH . ' characters',
        };
    }
}
