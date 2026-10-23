<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Graphing\Application\Port;

/** Graphing decides how far in the future a sample may be against an injected instant, never the system clock. */
interface Clock
{
    public function now(): \DateTimeImmutable;
}
