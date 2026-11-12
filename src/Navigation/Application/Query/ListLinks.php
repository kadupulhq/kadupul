<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Navigation\Application\Query;

use Kadupul\Navigation\Application\Port\LinkAccess;
use Kadupul\Navigation\Application\Port\LinkStore;

/** Reads external links for an authorized user. */
final readonly class ListLinks
{
    /** Wires the access check and the link store. */
    public function __construct(private LinkAccess $access, private LinkStore $store) {}
    /**
     * Returns every link with its revision, or one filtered page when filters are given.
     *
     * @throws LinkAccessDenied when the user may not manage links
     */
    public function __invoke(?array $filters = null): array
    {
        $this->access->authorize();
        return $filters === null ? $this->store->snapshot() : $this->store->list($filters);
    }
}
