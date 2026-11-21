<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Alerting\Application\Port;

/**
 * When the warning that a run evaluated nothing because the rate source is
 * unavailable was last logged, so RunAlerting can repeat it at most once an
 * hour: a poller-started run's output goes nowhere, and the log is the only
 * trace an inactive run leaves.
 */
interface InactiveWarningRecord
{
    /** The Unix time of the last warning, or null when none was recorded or the record cannot be read. */
    public function lastWarnedAt(): ?int;

    public function warnedAt(int $at): void;
}
