<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Graphing\Application\Port;

use Kadupul\IdentityAccess\Contract\Actor;

interface GraphTemplateInputAccess
{
    /** The Graph Templates realm (10), as graph_templates_inputs.php required it. */
    public function authorize(): Actor;

    /** Recheck the account, the console realm and the templates realm inside the write transaction. */
    public function assertCurrent(int $actorId): void;
}
