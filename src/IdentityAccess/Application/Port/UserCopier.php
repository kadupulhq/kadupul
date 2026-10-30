<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\IdentityAccess\Application\Port;

use Kadupul\IdentityAccess\Contract\OperatorDatabase;

/** Copies one local account, with its permissions, realms, settings and groups, to a new one. */
interface UserCopier
{
    /** Whether a local-realm account (realm 0) has this exact username. */
    public function localUserExists(OperatorDatabase $database, string $username): bool;

    /**
     * Runs the legacy copy, which keeps its plugin hooks and its transaction.
     * False when it refused or failed, for example because the new name is taken.
     *
     * @phpstan-impure
     * @throws \RuntimeException when the copy's outcome is unknown
     */
    public function copy(OperatorDatabase $database, string $template, string $new): bool;
}
