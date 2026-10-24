<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Graphing\Application\Port;

/** What a write needs to know about an existing RRD file before touching it. */
final readonly class RrdFileInfo
{
    /**
     * @param int $lastUpdate epoch second of the newest row; RRDtool refuses anything at or before it
     * @param array<string, string> $dataSourceTypes data-source name to the type RRDtool reports, such as COUNTER or DCOUNTER
     */
    public function __construct(
        public int $lastUpdate,
        public array $dataSourceTypes,
    ) {}

    public function typeOf(string $dataSourceName): ?string
    {
        return $this->dataSourceTypes[$dataSourceName] ?? null;
    }
}
