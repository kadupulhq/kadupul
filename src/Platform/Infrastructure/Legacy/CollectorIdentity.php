<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Infrastructure\Legacy;

final readonly class CollectorIdentity
{
    public function __construct(private InstallationConfiguration $configuration) {}

    public function isRemoteCollector(): bool
    {
        // Read on each call, not at construction: the container builds this
        // for every command, and a missing config.php must fail only when used.
        return $this->configuration->databaseTargets()['collector_id'] !== 1;
    }
}
