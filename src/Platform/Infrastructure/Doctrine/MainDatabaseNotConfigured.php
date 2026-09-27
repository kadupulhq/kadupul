<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Infrastructure\Doctrine;

/**
 * Not a Doctrine driver exception on purpose: DBAL converts only those when a
 * connection opens, so this one reaches callers unwrapped and they can name
 * the problem without reading exception text.
 */
final class MainDatabaseNotConfigured extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('Main database is not configured.');
    }
}
