<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace Kadupul\GraphDefinition\Domain;

final readonly class CdefSummary
{
    public function __construct(
        public int $id,
        public string $name,
        public int $graphs,
        public int $templates,
        public int $referencingCdefs,
    ) {}

    public function inUse(): bool
    {
        return $this->graphs > 0 || $this->templates > 0 || $this->referencingCdefs > 0;
    }
}
