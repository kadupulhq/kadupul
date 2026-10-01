<?php

/* SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-2.0-or-later */

namespace Kadupul\DataInput\Application\Port;

use Kadupul\IdentityAccess\Contract\Actor;

interface DataInputAccess
{
    public function authorize(): Actor;
    public function assertCurrent(int $actorId): void;
}
