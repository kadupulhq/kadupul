<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Alerting\Application\Port;

/** Opens and closes the single active incident of a rule (ADR 0011 decision 6). */
interface IncidentRepository
{
    public function open(int $ruleId, int $observedAt): void;

    public function close(int $ruleId, int $observedAt): void;
}
