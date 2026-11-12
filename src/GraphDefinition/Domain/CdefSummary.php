<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\GraphDefinition\Domain;

/** A CDEF as the list shows it, with how many graphs, templates, other CDEFs and aggregates use it. */
final readonly class CdefSummary
{
    /**
     * @param int $graphs graphs that use the CDEF
     * @param int $templates graph templates that use it
     * @param int $referencingCdefs other CDEFs that include it
     * @param int $aggregates aggregate graph items and templates that use it
     */
    public function __construct(
        public int $id,
        public string $name,
        public int $graphs,
        public int $templates,
        public int $referencingCdefs,
        public int $aggregates = 0,
    ) {}

    /** The legacy list disabled graph and template users; nested and aggregate references also block deletion. */
    public function isDeletable(): bool
    {
        return $this->graphs === 0 && $this->templates === 0 && $this->referencingCdefs === 0 && $this->aggregates === 0;
    }
}
