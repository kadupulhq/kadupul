<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Collection\Application\Query;

final class AutomationAccessDenied extends \RuntimeException
{
    public function __construct(public readonly bool $unauthenticated)
    {
        parent::__construct($unauthenticated ? 'Authentication required.' : 'Automation realm access required.');
    }
}
