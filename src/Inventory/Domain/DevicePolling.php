<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Inventory\Domain;

final readonly class DevicePolling
{
    public const DEFAULTS = ['device_threads' => '1', 'snmp_port' => '161', 'snmp_timeout' => '500', 'max_oids' => '10', 'bulk_walk_size' => '0', 'availability_method' => '2', 'ping_method' => '1', 'ping_port' => '23', 'ping_timeout' => '500', 'ping_retries' => '2'];
    public const RANGES = ['device_threads' => [1, 255], 'snmp_port' => [0, 65535], 'snmp_timeout' => [1, 16777215], 'max_oids' => [0, 60], 'ping_port' => [0, 65535], 'ping_timeout' => [0, 4294967295], 'ping_retries' => [0, 100]];
    public array $fields;

    public function __construct(array $fields)
    {
        if (array_diff_key($fields, self::DEFAULTS) !== [] || array_diff_key(self::DEFAULTS, $fields) !== []) {
            throw new \InvalidArgumentException('Submit all polling settings.');
        }
        $normalized = [];
        foreach (self::DEFAULTS as $key => $default) {
            $value = $fields[$key];
            if (!is_string($value) && !is_int($value)) {
                throw new \InvalidArgumentException('A device numeric setting is outside its supported range.');
            }
            $value = (string) $value;
            if ($key === 'bulk_walk_size' && $value === '-1') {
                $normalized[$key] = $value;
                continue;
            }
            if (!ctype_digit($value) || strlen($value) > 10) {
                throw new \InvalidArgumentException('A device numeric setting is outside its supported range.');
            }
            [$min, $max] = self::RANGES[$key] ?? match ($key) {
                'bulk_walk_size' => [0, 60],
                'availability_method' => [0, 6],
                'ping_method' => [0, 5],
            };
            if ((int) $value < $min || (int) $value > $max || ($key === 'ping_method' && (int) $value === 4)) {
                throw new \InvalidArgumentException('A device numeric setting is outside its supported range.');
            }
            $normalized[$key] = (string) (int) $value;
        }
        $this->fields = $normalized;
    }
}
