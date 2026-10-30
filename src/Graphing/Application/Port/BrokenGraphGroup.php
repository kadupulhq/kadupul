<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Graphing\Application\Port;

/** A graph template and the graphs built from it that have an item pointing at a missing data source. */
final readonly class BrokenGraphGroup
{
    /** @param list<GraphTitle> $graphs in local graph id order */
    public function __construct(public int $graphTemplateId, public string $name, public array $graphs) {}
}
