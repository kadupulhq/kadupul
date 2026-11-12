<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Monitoring\Domain;

/** A graph a pack suggests: a named set of its metrics sharing one unit. */
final readonly class PackGraph
{
    /** @param list<string> $metrics metric ids */
    public function __construct(public string $name, public array $metrics, public string $unit) {}
}
