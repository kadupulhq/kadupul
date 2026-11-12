<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\GraphDefinition\Application\Query;

/** Thrown when the user may not manage CDEFs; $unauthenticated tells a missing sign-in (401) from missing rights (403). */
final class CdefAccessDenied extends \RuntimeException
{
    /**
     * @param bool $unauthenticated true when nobody is signed in
     */
    public function __construct(public readonly bool $unauthenticated = false)
    {
        parent::__construct('Access denied.');
    }
}
