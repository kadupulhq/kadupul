<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Contract;

/** Verify the current server-selected connection; never select from client input. */
interface DatabaseTargetSelection
{
    public function select(string $expected): ?\PDO;
}
