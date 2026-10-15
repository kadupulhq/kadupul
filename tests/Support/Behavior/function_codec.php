<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/*
 * Tagged value encoding for function-level captures.
 *
 * JSON cannot say "this is the string '0', not the integer 0", cannot carry
 * invalid UTF-8, and has no NaN, INF or -0.0. The case files and the records
 * both use this one encoding so a recorded value can be fed back as an input.
 *
 *   null, true, false, ints, floats                        themselves
 *   strings that are valid UTF-8 and hold no control byte  themselves (tab, LF and CR count as text)
 *   [a, b]                                                 a PHP list
 *   {"$":"array","pairs":[[k,v],...]}                      any other array, in order
 *   {"$":"bytes","hex":"c328"}                             a string, byte for byte
 *   {"$":"float","v":"nan|inf|-inf|-0.0"}                  floats JSON cannot hold
 *   {"$":"repeat","unit":"ab","count":4096}                input only: a long string
 *   {"$":"long","bytes":N,"sha256":"..","head_hex":".."}    output only: a string over 4096 bytes
 *   {"$":"object","class":"X","props":[[k,v],...]}         output only
 *   {"$":"resource","type":"stream"}                       output only
 *
 * A bare JSON object without "$" is rejected, because json_decode() would turn
 * it into an array and hide whether the author meant a list or a map.
 */

const BEHAVIOR_REPEAT_LIMIT = 16777216;
const BEHAVIOR_LONG_STRING = 4096;

function behavior_decode($value)
{
    if (!is_array($value)) {
        return $value;
    }

    if (array_is_list($value)) {
        return array_map('behavior_decode', $value);
    }

    $tag = $value['$'] ?? null;
    if (!is_string($tag)) {
        throw new InvalidArgumentException('Object without a "$" tag; use {"$":"array","pairs":[...]}');
    }

    switch ($tag) {
        case 'bytes':
            $bytes = is_string($value['hex'] ?? null) && preg_match('/^(?:[0-9a-f]{2})*$/', $value['hex']) ? hex2bin($value['hex']) : false;
            if ($bytes === false) {
                throw new InvalidArgumentException('bytes needs lowercase even-length hex');
            }

            return $bytes;
        case 'float':
            $named = ['nan' => NAN, 'inf' => INF, '-inf' => -INF, '-0.0' => -0.0];
            if (!is_string($value['v'] ?? null) || !array_key_exists($value['v'], $named)) {
                throw new InvalidArgumentException('float needs v of nan, inf, -inf or -0.0');
            }

            return $named[$value['v']];
        case 'array':
            $out = [];
            foreach ($value['pairs'] ?? [] as $pair) {
                if (!is_array($pair) || count($pair) !== 2 || !is_int($pair[0] ?? null) && !is_string($pair[0] ?? null)) {
                    throw new InvalidArgumentException('array pairs must be [int|string key, value]');
                }
                $out[behavior_decode($pair[0])] = behavior_decode($pair[1]);
            }

            return $out;
        case 'repeat':
            $unit = behavior_decode($value['unit'] ?? null);
            $count = $value['count'] ?? null;
            if (!is_string($unit) || $unit === '' || !is_int($count) || $count < 0 || strlen($unit) * $count > BEHAVIOR_REPEAT_LIMIT) {
                throw new InvalidArgumentException('repeat needs a non-empty string unit and a bounded count');
            }

            return str_repeat($unit, $count);
    }

    throw new InvalidArgumentException('Unknown tag ' . $tag);
}

function behavior_encode($value, $limit = BEHAVIOR_LONG_STRING)
{
    if (is_float($value)) {
        if (is_nan($value)) {
            return ['$' => 'float', 'v' => 'nan'];
        }
        if (is_infinite($value)) {
            return ['$' => 'float', 'v' => $value > 0 ? 'inf' : '-inf'];
        }
        if ($value === 0.0 && fdiv(1, $value) < 0) {
            return ['$' => 'float', 'v' => '-0.0'];
        }

        return $value;
    }

    if (is_string($value)) {
        // A long result is compared by digest; the golden stays reviewable and
        // any change to a single byte still changes the record.
        if (strlen($value) > $limit) {
            return ['$' => 'long', 'bytes' => strlen($value), 'sha256' => hash('sha256', $value), 'head_hex' => bin2hex(substr($value, 0, 32))];
        }
        // Control bytes would read as noise in a golden, and packed addresses
        // and masks are exactly that; spell them out in hex.
        return preg_match('//u', $value) && !preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $value) ? $value : ['$' => 'bytes', 'hex' => bin2hex($value)];
    }

    if (is_array($value)) {
        if (array_is_list($value)) {
            return array_map(fn($item) => behavior_encode($item, $limit), $value);
        }
        $pairs = [];
        foreach ($value as $k => $v) {
            $pairs[] = [$k, behavior_encode($v, $limit)];
        }

        return ['$' => 'array', 'pairs' => $pairs];
    }

    if (is_object($value)) {
        $props = [];
        foreach (get_object_vars($value) as $k => $v) {
            $props[] = [$k, behavior_encode($v, $limit)];
        }

        return ['$' => 'object', 'class' => get_class($value), 'props' => $props];
    }

    if (is_resource($value)) {
        return ['$' => 'resource', 'type' => get_resource_type($value)];
    }

    return $value;
}

/*
 * Key order inside records is not behavior, so object keys are sorted. The
 * order of array elements is behavior and lives in lists, which are untouched.
 */
function behavior_sort_keys($value)
{
    if (!is_array($value)) {
        return $value;
    }
    if (!array_is_list($value)) {
        ksort($value, SORT_STRING);
    }

    return array_map('behavior_sort_keys', $value);
}

function behavior_json($value)
{
    return json_encode(behavior_sort_keys($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
}
