<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Inventory\Application\ReadModel;

final readonly class SitePage
{
    /** @param list<SiteSummary> $sites */
    public function __construct(public array $sites, public bool $hasNext) {}
}
