<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2004-2026 The Cacti Group
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace Kadupul\Navigation\Application\Query;

/** Thrown when the user may not view a list; $unauthenticated tells a missing sign-in (401) from missing rights (403). */
final class AdministrationListDenied extends \RuntimeException
{
    /**
     * @param bool $unauthenticated true when nobody is signed in
     */
    public function __construct(public readonly bool $unauthenticated = false)
    {
        parent::__construct('Access denied.');
    }
}
