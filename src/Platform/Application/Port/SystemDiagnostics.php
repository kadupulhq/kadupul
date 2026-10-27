<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Application\Port;

use Kadupul\Platform\Application\ReadModel\DiagnosticPage;
use Kadupul\Platform\Domain\Diagnostics\DiagnosticFilters;
use Kadupul\Platform\Domain\Diagnostics\DiagnosticScreen;

interface SystemDiagnostics
{
    public function read(DiagnosticScreen $screen, DiagnosticFilters $filters, int $actorId): DiagnosticPage;
}
