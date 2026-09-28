<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Collection\Application\ReadModel;

final readonly class AutomationGraphRulePage
{
    /** @param list<AutomationGraphRuleSummary> $rules */
    public function __construct(public array $rules, public bool $hasNext) {}
}
