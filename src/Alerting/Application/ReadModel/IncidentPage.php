<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Alerting\Application\ReadModel;

final readonly class IncidentPage
{
    /** @param list<IncidentView> $incidents */
    public function __construct(
        public array $incidents,
        public int $total,
        public int $page,
        public int $rows,
        public bool $activeOnly,
    ) {}

    public function hasPrevious(): bool
    {
        return $this->page > 1;
    }

    public function hasNext(): bool
    {
        return $this->page * $this->rows < $this->total;
    }
}
