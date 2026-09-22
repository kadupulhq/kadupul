<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Inventory\Application\Query;

final readonly class DeviceCreationChoices
{
    /** Reference choices map positive IDs to display names. Defaults contain no credentials. */
    public function __construct(public array $defaults, public array $templates, public array $sites, public array $pollers) {}
}
