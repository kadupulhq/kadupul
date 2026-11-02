<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Contract;

/** Preserve the native installation redirect across authenticated feature gateways. */
interface InstallationUpgradeRequired extends \Throwable
{
    public function location(): string;
}
