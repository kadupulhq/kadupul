<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Contract;

enum BrowserWorkerMode
{
    case Read;
    case Mutation;

    public function inputLimit(): int
    {
        return match ($this) {
            self::Read => 65536,
            self::Mutation => 1048576,
        };
    }
}
