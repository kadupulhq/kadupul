<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Graphing\Application\Command;

/** reorder_data_query.php refused to run on a remote collector. */
final class MainCollectorOnly extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('This utility is designed for the main Data Collector only');
    }
}
