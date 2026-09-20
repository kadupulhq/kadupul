<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Inventory\Application\ReadModel;

final readonly class DeviceSummary
{
    public function __construct(public int $id, public string $description, public string $hostname, public bool $disabled, public string $status) {}
}
