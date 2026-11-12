<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Navigation\Application\Query;

/** Thrown when the user may not manage links; $unauthenticated tells a missing sign-in (401) from missing rights (403). */
final class LinkAccessDenied extends \RuntimeException
{
    /**
     * @param bool $unauthenticated true when nobody is signed in
     */
    public function __construct(public readonly bool $unauthenticated)
    {
        parent::__construct('Access denied.');
    }
}
