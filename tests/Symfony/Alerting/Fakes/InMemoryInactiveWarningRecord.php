<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Tests\Alerting\Fakes;

use Kadupul\Alerting\Application\Port\InactiveWarningRecord;

final class InMemoryInactiveWarningRecord implements InactiveWarningRecord
{
    public function __construct(public ?int $at = null) {}

    public function lastWarnedAt(): ?int
    {
        return $this->at;
    }

    public function warnedAt(int $at): void
    {
        $this->at = $at;
    }
}
