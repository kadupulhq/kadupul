<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Automation\Domain;

final readonly class AutomationTemplatePage
{
    /**
     * @param list<AutomationTemplate> $templates in match order
     * @param ?int $first ID of the first template in the whole match order, which cannot move up
     * @param ?int $last ID of the last one, which cannot move down
     */
    public function __construct(
        public array $templates,
        public int $total,
        public AutomationTemplateFilters $filters,
        public string $order,
        public ?int $first,
        public ?int $last,
    ) {}

    public function hasPrevious(): bool
    {
        return $this->filters->page > 1;
    }

    public function hasNext(): bool
    {
        return $this->filters->page * $this->filters->rows < $this->total;
    }
}
