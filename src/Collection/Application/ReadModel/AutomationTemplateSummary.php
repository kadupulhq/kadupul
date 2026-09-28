<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Collection\Application\ReadModel;

final readonly class AutomationTemplateSummary
{
    public function __construct(
        public int $id,
        public string $hostTemplate,
        public string $availabilityMethod,
        public string $systemDescription,
        public string $systemName,
        public string $systemObjectId,
        public int $sequence
    ) {}
}
