<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Graphing\Application\Port;

use Kadupul\IdentityAccess\Contract\OperatorDatabase;

/** The setting rebuild_poller_cache.php read before its arguments, for the one message that quotes it. */
interface PollerCacheSettings
{
    /**
     * read_config_option('commands_processes') as the script read it: the
     * stored text, or the setting's default "1" when no row exists.
     */
    public function concurrentProcesses(OperatorDatabase $database): string;
}
