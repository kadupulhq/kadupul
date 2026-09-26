<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Domain\Schema;

final class InvalidConversionOptions extends \InvalidArgumentException
{
    public function __construct(public readonly ConversionProblem $problem)
    {
        parent::__construct('Invalid table conversion options.');
    }
}
