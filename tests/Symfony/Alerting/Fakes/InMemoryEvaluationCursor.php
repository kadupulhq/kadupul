<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Tests\Alerting\Fakes;

use Kadupul\Alerting\Application\Port\EvaluationCursor;

final class InMemoryEvaluationCursor implements EvaluationCursor
{
    public function __construct(private int $position = 0) {}

    public function position(): int
    {
        return $this->position;
    }

    public function moveTo(int $afterRuleId): void
    {
        $this->position = $afterRuleId;
    }
}
