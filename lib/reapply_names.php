<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/** Validate a host selector used by the data-source and graph rename tools. */
function validate_reapply_host_selector($host_id)
{
    if ($host_id === '0' || strtolower($host_id) === 'all') {
        return true;
    }

    foreach (explode(',', $host_id) as $host) {
        if (!ctype_digit($host) || (int) $host < 1 || (int) $host > 4294967295) {
            return false;
        }
    }

    return true;
}
