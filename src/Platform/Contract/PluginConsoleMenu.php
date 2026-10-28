<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Contract;

/**
 * The console menu as the legacy bootstrap leaves it after plugins' config_arrays
 * hooks have run. Entries are untrusted plugin data: callers validate paths and
 * filter by realm before showing anything.
 */
interface PluginConsoleMenu
{
    /**
     * @param list<string> $sections English section names the caller already
     *     shows; an entry under one of them reports that name instead of its
     *     translated heading, so it can join the caller's section in any locale
     * @return list<array{section: string, label: string, path: string, realm: ?int}>
     *     realm is the one $user_auth_realm_filenames assigns the script, or null
     * @throws \RuntimeException when the entries cannot be confirmed
     */
    public function entries(int $actorId, array $sections): array;
}
