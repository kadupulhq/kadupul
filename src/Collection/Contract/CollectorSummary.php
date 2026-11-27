<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Collection\Contract;

final readonly class CollectorSummary
{
    public function __construct(
        public int $id,
        public string $name,
        public string $hostname,
        public int $status,
        public string $statusLabel,
        public bool $disabled,
        public int $processes,
        public int $threads,
        public int $syncInterval,
        public string $lastUpdate,
    ) {}
}
