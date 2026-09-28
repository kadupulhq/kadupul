<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Collection\Application\ReadModel;

final readonly class AutomationGraphRuleSummary
{
    public function __construct(
        public int $id,
        public string $name,
        public string $dataQuery,
        public string $graphType,
        public bool $enabled
    ) {}
}
