<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\GraphDefinition\Domain;

/** A VDEF as the list shows it, with how many graphs, templates and other VDEFs use it. */
final readonly class VdefSummary
{
    /**
     * @param int $graphs graphs that use the VDEF
     * @param int $templates graph templates that use it
     * @param int $referencingVdefs other VDEFs that include it
     */
    public function __construct(
        public int $id,
        public string $name,
        public int $graphs,
        public int $templates,
        public int $referencingVdefs = 0,
    ) {}

    /** True when a graph, a template or another VDEF uses it. */
    public function inUse(): bool
    {
        return $this->graphs > 0 || $this->templates > 0 || $this->referencingVdefs > 0;
    }
}
