<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Inventory\Domain;

final readonly class DeviceOrder
{
    public function __construct(public string $field = 'name', public string $direction = 'asc')
    {
        if (!in_array($field, ['name', 'hostname'], true) || !in_array($direction, ['asc', 'desc'], true)) {
            throw new \InvalidArgumentException('Invalid device ordering.');
        }
    }
}
