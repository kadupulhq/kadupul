<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Graphing\Domain\Font;

/** One graph element's font; an empty family leaves the choice to RRDtool. */
final readonly class GraphFont
{
    public function __construct(public string $family, public float $size) {}
}
