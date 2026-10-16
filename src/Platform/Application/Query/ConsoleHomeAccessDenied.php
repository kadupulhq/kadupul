<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Application\Query;

final class ConsoleHomeAccessDenied extends \RuntimeException
{
    public function __construct(public readonly bool $anonymous)
    {
        parent::__construct('Console access is required.');
    }
}
