<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors.
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\ColorTemplates\Application\Port;

use Kadupul\IdentityAccess\Contract\Actor;

interface ColorTemplateAccess
{
    public function authorize(): Actor;

    public function assertCurrent(int $actorId): void;
}
