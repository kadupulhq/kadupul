<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Inventory\Application\ReadModel;

final readonly class SiteSummary
{
    public function __construct(public int $id, public string $name, public string $city, public string $state, public string $country, public int $visibleDevices) {}
}
