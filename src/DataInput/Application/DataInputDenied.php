<?php

/* SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later */

namespace Kadupul\DataInput\Application;

final class DataInputDenied extends \RuntimeException
{
    public function __construct(public readonly bool $anonymous = false)
    {
        parent::__construct('Access denied.');
    }
}
