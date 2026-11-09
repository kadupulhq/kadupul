<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Navigation\Application\Port;

/** The configured page size legacy lists use for rows=-1 or no rows value. */
interface ListPageSize
{
    /** @return int<1, 5000> */
    public function defaultRows(): int;
}
