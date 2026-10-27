<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Application\ReadModel;

final readonly class DiagnosticPage
{
    /**
     * @param list<string> $columns
     * @param list<list<string|int|null>> $rows
     */
    public function __construct(public array $columns, public array $rows, public bool $hasNext = false) {}
}
