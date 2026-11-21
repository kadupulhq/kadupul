<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Alerting\Application\Port;

/** Where a run that stopped on its time budget resumes: after this rule id, 0 for the start. */
interface EvaluationCursor
{
    public function position(): int;

    public function moveTo(int $afterRuleId): void;
}
