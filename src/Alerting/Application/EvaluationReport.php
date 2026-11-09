<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Alerting\Application;

final readonly class EvaluationReport
{
    /**
     * @param int     $evaluated      rules whose verdict was applied and state saved
     * @param int     $skipped        rules whose latest observation was already counted
     * @param ?string $inactiveReason set when nothing was evaluated because the source cannot work
     */
    public function __construct(
        public int $evaluated,
        public int $skipped,
        public ?string $inactiveReason = null,
    ) {}

    public static function inactive(string $reason): self
    {
        return new self(0, 0, $reason);
    }
}
