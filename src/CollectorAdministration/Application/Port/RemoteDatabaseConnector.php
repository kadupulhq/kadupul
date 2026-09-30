<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\CollectorAdministration\Application\Port;

interface RemoteDatabaseConnector
{
    /** @param array<string, mixed> $credentials */
    public function connect(array $credentials): bool;
}
