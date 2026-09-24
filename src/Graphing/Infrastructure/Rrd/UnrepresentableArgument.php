<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Graphing\Infrastructure\Rrd;

final class UnrepresentableArgument extends \InvalidArgumentException
{
    public function __construct()
    {
        // The argument itself is left out: it may be a device value or a path.
        parent::__construct('An RRDtool pipe argument cannot contain NUL, CR or LF.');
    }
}
