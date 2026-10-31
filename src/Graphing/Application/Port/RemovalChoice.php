<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Graphing\Application\Port;

/** A device template or graph template as the --list-* modes print it. */
final readonly class RemovalChoice
{
    public function __construct(public int $id, public string $name) {}
}
