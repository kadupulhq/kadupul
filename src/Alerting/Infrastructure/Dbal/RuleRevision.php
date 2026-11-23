<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Alerting\Infrastructure\Dbal;

/**
 * Identifies the stored state of an alert_rule row, so a form opened before
 * another change cannot overwrite or delete it. Doubles are written at full
 * precision, so two different thresholds never share a revision.
 */
final class RuleRevision
{
    public const array COLUMNS = ['id', 'local_data_id', 'rrd_name', 'kind', 'comparator', 'trigger_threshold', 'clear_threshold',
        'baseline_direction', 'baseline_deviations', 'baseline_clear_deviations', 'baseline_floor', 'baseline_min_samples', 'for_cycles', 'owner_id'];

    /** @param array<string, mixed> $row the COLUMNS of one alert_rule row */
    public static function of(array $row): string
    {
        $parts = [];
        foreach (self::COLUMNS as $column) {
            $value = $row[$column] ?? null;
            $parts[] = match (true) {
                $value === null => "\0",
                is_float($value) => sprintf('%.17G', $value),
                // A double read as text is normalized the same way.
                is_string($value) && in_array($column, ['trigger_threshold', 'clear_threshold', 'baseline_deviations', 'baseline_clear_deviations', 'baseline_floor'], true) && is_numeric($value) => sprintf('%.17G', (float) $value),
                is_scalar($value) => (string) $value,
                default => throw StoredAlertingRowUnreadable::because('alert_rule', $column . ' is not a stored value.'),
            };
        }

        return substr(hash('sha256', implode("\x1f", $parts)), 0, 32);
    }
}
