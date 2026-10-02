<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\GraphDefinition\Domain;

final readonly class VdefSummary
{
    public function __construct(
        public int $id,
        public string $name,
        public int $graphs,
        public int $templates,
        public int $referencingVdefs = 0,
    ) {}

    public function inUse(): bool
    {
        return $this->graphs > 0 || $this->templates > 0 || $this->referencingVdefs > 0;
    }
}
