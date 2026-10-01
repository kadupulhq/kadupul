<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\GraphDefinition\Domain;

final class CdefRevisionConflict extends \InvalidArgumentException
{
    public function __construct()
    {
        parent::__construct('The CDEF changed. Reload the form.');
    }
}
