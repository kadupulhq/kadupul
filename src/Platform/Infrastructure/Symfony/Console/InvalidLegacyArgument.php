<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Infrastructure\Symfony\Console;

final class InvalidLegacyArgument extends \InvalidArgumentException
{
    public function __construct(public readonly string $argument)
    {
        parent::__construct('Invalid legacy argument.');
    }
}
