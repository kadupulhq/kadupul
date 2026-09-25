<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Application\ReadModel;

/** What upgrade_database() printed, which the legacy output passes through unchanged. */
final readonly class UpgradeOutput
{
    /** @param bool $completed true only when the worker ran to the end and cli/upgrade_database.php exited 0 */
    public function __construct(public string $stdout, public string $stderr, public bool $completed) {}
}
