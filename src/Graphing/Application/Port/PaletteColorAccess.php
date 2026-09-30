<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Graphing\Application\Port;

use Kadupul\IdentityAccess\Contract\Actor;

interface PaletteColorAccess
{
    public function authorize(): Actor;
    public function assertCurrent(int $actorId): void;
}
