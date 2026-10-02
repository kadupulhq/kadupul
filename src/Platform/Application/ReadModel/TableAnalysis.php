<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Application\ReadModel;

/** An immutable result for one ANALYZE TABLE operation. */
final readonly class TableAnalysis
{
    public function __construct(public string $name, public AnalysisOutcome $outcome) {}

    public function succeeded(): bool
    {
        return $this->outcome === AnalysisOutcome::Succeeded;
    }

    /** @return array{name: string, ok: bool} The existing CLI JSON contract. */
    public function toArray(): array
    {
        return ['name' => $this->name, 'ok' => $this->succeeded()];
    }
}
