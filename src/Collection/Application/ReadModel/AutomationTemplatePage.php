<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Collection\Application\ReadModel;

final readonly class AutomationTemplatePage
{
    /** @param list<AutomationTemplateSummary> $templates */
    public function __construct(public array $templates, public bool $hasNext) {}
}
