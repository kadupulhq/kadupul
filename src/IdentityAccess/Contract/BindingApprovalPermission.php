<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\IdentityAccess\Contract;

/**
 * Whether an actor may approve a model-driven monitoring binding, which decides
 * where samples are stored. Separate from ConsoleAccess so that widening it to
 * a dedicated realm later changes one adapter, not the callers.
 */
interface BindingApprovalPermission
{
    public function mayApproveBindings(Actor $actor): bool;
}
