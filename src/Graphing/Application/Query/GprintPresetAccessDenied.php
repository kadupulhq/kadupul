<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Graphing\Application\Query;

final class GprintPresetAccessDenied extends \RuntimeException
{
    public function __construct(public readonly bool $unauthenticated)
    {
        parent::__construct('Access denied.');
    }
}
