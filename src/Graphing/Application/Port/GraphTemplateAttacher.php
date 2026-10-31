<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Graphing\Application\Port;

use Kadupul\IdentityAccess\Contract\OperatorDatabase;

/** Associates a graph template with a device through the legacy code that owns the automation and plugin hooks. */
interface GraphTemplateAttacher
{
    /**
     * Writes the host_graph row, runs automation_hook_graph_template() and the
     * add_graph_template_to_host plugin hook, as add_graph_template.php did.
     *
     * @phpstan-impure
     * @throws \RuntimeException when the outcome is unknown or the worker failed
     */
    public function attach(OperatorDatabase $database, int $deviceId, int $graphTemplateId): void;
}
