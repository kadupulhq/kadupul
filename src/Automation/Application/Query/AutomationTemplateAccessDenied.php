<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Automation\Application\Query;

final class AutomationTemplateAccessDenied extends \RuntimeException
{
    public function __construct(public readonly bool $unauthenticated = false)
    {
        parent::__construct('Access denied.');
    }
}
