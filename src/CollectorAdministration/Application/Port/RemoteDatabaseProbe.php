<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\CollectorAdministration\Application\Port;

interface RemoteDatabaseProbe
{
    /** @param array<string, mixed> $credentials */
    public function canConnect(int $actorId, array $credentials, ?int $collectorId = null, ?string $revision = null): bool;
}
