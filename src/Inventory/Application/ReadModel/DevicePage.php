<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Inventory\Application\ReadModel;

final readonly class DevicePage
{
    /** @param list<DeviceSummary> $devices */
    public function __construct(public array $devices, public bool $hasNext) {}
}
