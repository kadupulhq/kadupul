<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Telemetry\Application\Contract;

/** Telemetry owns this so staleness is decided against an injected instant, never the system clock. */
interface Clock
{
    public function now(): \DateTimeImmutable;
}
