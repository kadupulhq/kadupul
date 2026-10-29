<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/*
 * The sample model shared by seed.php (which writes step 0 into each new RRD,
 * as a previous poll would have) and generate.php (which queues steps 1 to 6
 * in poller_output_boost). Every value is a pure function of the seed, the
 * data source ordinal and the step, so a run can be regenerated exactly.
 */

const KBL_STEP = 300;
const KBL_STEPS = 6;

/**
 * Each kind clones one base data source. 'fields' maps the poller output
 * field (rrd_name, or the name inside a multi-field output) to the RRD data
 * source name. 'type' overrides data_template_rrd.data_source_type_id on the
 * clone; null keeps the stock template's type.
 *
 * @return array<string, array{share: int, base: string, multi: bool, type: ?int, fields: array<string, string>}>
 */
function kbl_kinds(): array
{
    return [
        // Shares are per mille and must add up to 1000.
        'gauge' => ['share' => 400, 'base' => 'gauge', 'multi' => false, 'type' => null, 'fields' => ['proc' => 'proc']],
        'counter' => ['share' => 250, 'base' => 'interface', 'multi' => false, 'type' => null, 'fields' => ['traffic_in' => 'traffic_in', 'traffic_out' => 'traffic_out']],
        'derive' => ['share' => 100, 'base' => 'interface', 'multi' => false, 'type' => 3, 'fields' => ['traffic_in' => 'traffic_in', 'traffic_out' => 'traffic_out']],
        'absolute' => ['share' => 100, 'base' => 'users', 'multi' => false, 'type' => 4, 'fields' => ['users' => 'users']],
        'multi' => ['share' => 100, 'base' => 'loadavg', 'multi' => true, 'type' => null, 'fields' => ['1min' => 'load_1min', '5min' => 'load_5min', '10min' => 'load_15min']],
        'wrap64' => ['share' => 50, 'base' => 'interface', 'multi' => false, 'type' => null, 'fields' => ['traffic_in' => 'traffic_in', 'traffic_out' => 'traffic_out']],
    ];
}

/** A deterministic 48-bit unsigned value for one (seed, ordinal, step, label). */
function kbl_hash(int $seed, int $n, int $step, string $label): int
{
    return (int) hexdec(substr(hash('xxh64', $seed . ':' . $n . ':' . $step . ':' . $label), 0, 12));
}

function kbl_kind(int $seed, int $n): string
{
    $slot = kbl_hash($seed, $n, 0, 'kind') % 1000;
    foreach (kbl_kinds() as $name => $kind) {
        if ($slot < $kind['share']) {
            return $name;
        }
        $slot -= $kind['share'];
    }

    throw new LogicException('Kind shares do not add up to 1000.');
}

/**
 * The value of one field at one step, as the poller would have stored it.
 * Counters accumulate from a per-source base; wrap64 starts close enough to
 * 2^64 that it wraps inside the six steps.
 */
function kbl_value(int $seed, int $n, string $kind, string $field, int $step): string
{
    if ($step > 0 && kbl_hash($seed, $n, $step, 'U:' . $field) % 200 === 0) {
        return 'U';
    }

    switch ($kind) {
        case 'gauge':
            return (string) (kbl_hash($seed, $n, $step, $field) % 5000);
        case 'absolute':
            return (string) (kbl_hash($seed, $n, $step, $field) % 1000);
        case 'multi':
            return sprintf('%.2f', (kbl_hash($seed, $n, $step, $field) % 1600) / 100);
        case 'counter':
        case 'derive':
            $value = kbl_hash($seed, $n, 0, 'base:' . $field) % (1 << 40);
            for ($s = 1; $s <= $step; $s++) {
                $increment = kbl_hash($seed, $n, $s, 'inc:' . $field) % 1000000000;
                // DERIVE may fall, which a COUNTER would read as a wrap.
                $value += $kind === 'derive' ? $increment - 400000000 : $increment;
            }

            return (string) $value;
        case 'wrap64':
            $value = gmp_sub(gmp_pow(2, 64), 1500000000 + kbl_hash($seed, $n, 0, 'base:' . $field) % 100000000);
            for ($s = 1; $s <= $step; $s++) {
                $value = gmp_add($value, kbl_hash($seed, $n, $s, 'inc:' . $field) % 1000000000);
            }

            return gmp_strval(gmp_mod($value, gmp_pow(2, 64)));
    }

    throw new InvalidArgumentException('Unknown kind ' . $kind);
}

/**
 * The poller_output_boost rows for one data source at one step:
 * list of [rrd_name, output].
 *
 * @return list<array{0: string, 1: string}>
 */
function kbl_rows(int $seed, int $n, string $kind, int $step): array
{
    $definition = kbl_kinds()[$kind];
    if ($definition['multi']) {
        $parts = [];
        foreach (array_keys($definition['fields']) as $field) {
            $parts[] = $field . ':' . kbl_value($seed, $n, $kind, $field, $step);
        }

        return [['', implode(' ', $parts)]];
    }

    $rows = [];
    foreach (array_keys($definition['fields']) as $field) {
        $rows[] = [$field, kbl_value($seed, $n, $kind, $field, $step)];
    }

    return $rows;
}
