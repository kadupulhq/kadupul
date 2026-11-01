<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Contract;

/** Server-selected database identity; never authority to select a client target. */
interface DatabaseTarget
{
    /**
     * Returns the identity fingerprint of the connection the server selected.
     *
     * @throws \RuntimeException when the target cannot be confirmed
     */
    public function capture(): string;
}
