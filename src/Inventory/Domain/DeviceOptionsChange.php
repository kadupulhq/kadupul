<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Inventory\Domain;

final readonly class DeviceOptionsChange
{
    public const DEFAULTS = ['location' => ''] + DevicePolling::DEFAULTS;
    public array $fields;

    public function __construct(array $fields)
    {
        if ($fields === [] || array_diff_key($fields, self::DEFAULTS) !== []) {
            throw new \InvalidArgumentException('Select at least one supported device option.');
        }
        if (array_key_exists('location', $fields)) {
            $location = $fields['location'];
            if (!is_string($location) || !mb_check_encoding($location, 'UTF-8') || mb_strlen($location, 'UTF-8') > 40 || str_contains($location, "\0")) {
                throw new \InvalidArgumentException('Location must be valid text of at most 40 characters.');
            }
        }
        $polling = array_intersect_key($fields, DevicePolling::DEFAULTS);
        $validated = (new DevicePolling(array_replace(DevicePolling::DEFAULTS, $polling)))->fields;
        $this->fields = array_intersect_key($validated, $polling) + array_intersect_key($fields, ['location' => '']);
    }
}
