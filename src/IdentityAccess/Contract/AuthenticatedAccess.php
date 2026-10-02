<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\IdentityAccess\Contract;

/** Eligible signed-in account without a console or object realm requirement. */
interface AuthenticatedAccess
{
    public function authenticatedActor(): ?Actor;
}
