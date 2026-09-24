<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Application\ReadModel;

final readonly class WideningReport
{
    /** @param list<array{table: string, column: ?string, event: WideningEvent, statement: ?string}> $steps in the original's order */
    public function __construct(public bool $main, public bool $dryRun, public array $steps) {}

    /** Tables a statement was sent or planned for, which is what fix_mediumint.php counted. */
    public function tables(): int
    {
        return count($this->altered());
    }

    public function failed(): int
    {
        return count(array_filter($this->steps, static fn(array $step): bool => $step['event'] === WideningEvent::Failed));
    }

    /** @return list<array{table: string, column: ?string, event: WideningEvent, statement: ?string}> */
    public function altered(): array
    {
        return array_values(array_filter(
            $this->steps,
            static fn(array $step): bool => in_array($step['event'], [WideningEvent::Planned, WideningEvent::Widened, WideningEvent::Failed], true),
        ));
    }
}
