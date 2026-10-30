<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\IdentityAccess\Contract;

/** Whether this installation is a remote data collector, whose writes belong on the main database. */
interface CollectorTopology
{
    public function isRemoteCollector(): bool;
}
