<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\DataInput\Application\Port;

use Kadupul\IdentityAccess\Contract\Actor;

/** Console access plus the Data Queries realm (13), as data_queries.php required. */
interface DataQueryAccess
{
    /** @throws \Kadupul\DataInput\Application\DataInputDenied */
    public function authorize(): Actor;
}
