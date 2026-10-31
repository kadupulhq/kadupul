<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Graphing\Application\Port;

/** A graph of a device as the --list-graphs mode prints it. */
final readonly class HostGraphRow
{
    public function __construct(public int $id, public string $name, public string $template) {}
}
