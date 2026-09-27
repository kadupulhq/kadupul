<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\CollectorAdministration\Application\ReadModel;

final readonly class CollectorPage
{
    /** @param list<CollectorSummary> $collectors */
    public function __construct(public array $collectors, public bool $hasNext, public int $pollerType = 1) {}
}
