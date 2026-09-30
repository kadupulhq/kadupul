<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace Kadupul\AggregateTemplate\Application\Query;

final class AggregateTemplateAccessDenied extends \RuntimeException
{
    public function __construct(public readonly bool $unauthenticated = false)
    {
        parent::__construct('Access denied.');
    }
}
