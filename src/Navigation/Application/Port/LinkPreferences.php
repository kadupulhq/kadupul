<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Navigation\Application\Port;

/** The signed-in user's remembered filters for the link list. */
interface LinkPreferences
{
    /** The signed-in user's remembered link list filters, or null when none are stored or they are unreadable. */
    public function load(): ?array;
    /**
     * Remembers the filters for the signed-in user; only rows, page, filter, sort_column and sort_direction are accepted.
     *
     * @throws \InvalidArgumentException when a key or value is not allowed
     */
    public function save(array $filters): void;
}
