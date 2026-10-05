<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

/** Reach repairs missed by installations already running 1.2.31 through 1.2.34. */
function upgrade_to_1_2_35(): void
{
    require_once dirname(__DIR__, 2) . '/lib/schema_repair_integrity.php';
    schema_repair_integrity();
}
