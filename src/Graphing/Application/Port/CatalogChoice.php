<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Graphing\Application\Port;

/** A device template or graph template as the --list-* modes of the graph tools print it. */
final readonly class CatalogChoice
{
    public function __construct(public int $id, public string $name) {}
}
