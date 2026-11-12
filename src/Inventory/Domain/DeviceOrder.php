<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Inventory\Domain;

/**
 * The sort field and direction for the device list.
 */
final readonly class DeviceOrder
{
    /**
     * Validates the ordering.
     *
     * @param string $field Either "name" or "hostname".
     * @param string $direction Either "asc" or "desc".
     * @throws \InvalidArgumentException When the field or direction is not supported.
     */
    public function __construct(public string $field = 'name', public string $direction = 'asc')
    {
        if (!in_array($field, ['name', 'hostname'], true) || !in_array($direction, ['asc', 'desc'], true)) {
            throw new \InvalidArgumentException('Invalid device ordering.');
        }
    }
}
