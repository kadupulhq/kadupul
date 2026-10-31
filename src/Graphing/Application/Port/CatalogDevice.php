<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Graphing\Application\Port;

/** A device as the --list-hosts modes of the graph tools print it. */
final readonly class CatalogDevice
{
    public function __construct(public int $id, public string $hostname, public int $hostTemplateId, public string $description) {}
}
