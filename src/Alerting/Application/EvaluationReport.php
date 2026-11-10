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
     * @param int     $orphaned       rules whose incident or state was ended because their rule or series is gone
     * @param int     $failed         rules skipped because a stored record of theirs cannot be used (UnusableRuleRecord)
     * @param bool    $pruneFailed    old baseline history could not be removed this run (BaselinePruneFailed)
     */
    public function __construct(
        public int $evaluated,
        public int $skipped,
        public ?string $inactiveReason = null,
        public int $orphaned = 0,
        public int $failed = 0,
        public bool $pruneFailed = false,
    ) {}

    public static function inactive(string $reason): self
    {
        return new self(0, 0, $reason);
    }
}
